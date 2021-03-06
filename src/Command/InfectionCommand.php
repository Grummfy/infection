<?php
/**
 * Copyright © 2017-2018 Maks Rafalko
 *
 * License: https://opensource.org/licenses/BSD-3-Clause New BSD License
 */

declare(strict_types=1);

namespace Infection\Command;

use Composer\XdebugHandler\XdebugHandler;
use Infection\Config\InfectionConfig;
use Infection\Console\ConsoleOutput;
use Infection\Console\Exception\ConfigurationException;
use Infection\Console\Exception\InfectionException;
use Infection\Console\Exception\InvalidOptionException;
use Infection\Console\LogVerbosity;
use Infection\Console\OutputFormatter\DotFormatter;
use Infection\Console\OutputFormatter\OutputFormatter;
use Infection\Console\OutputFormatter\ProgressFormatter;
use Infection\EventDispatcher\EventDispatcherInterface;
use Infection\Finder\Exception\LocatorException;
use Infection\Finder\Locator;
use Infection\Mutant\Generator\MutationsGenerator;
use Infection\Mutant\MetricsCalculator;
use Infection\Process\Builder\ProcessBuilder;
use Infection\Process\Listener\CleanUpAfterMutationTestingFinishedSubscriber;
use Infection\Process\Listener\InitialTestsConsoleLoggerSubscriber;
use Infection\Process\Listener\MutantCreatingConsoleLoggerSubscriber;
use Infection\Process\Listener\MutationGeneratingConsoleLoggerSubscriber;
use Infection\Process\Listener\MutationTestingConsoleLoggerSubscriber;
use Infection\Process\Listener\MutationTestingResultsLoggerSubscriber;
use Infection\Process\Runner\InitialTestsRunner;
use Infection\Process\Runner\MutationTestingRunner;
use Infection\TestFramework\AbstractTestFrameworkAdapter;
use Infection\TestFramework\Coverage\CodeCoverageData;
use Infection\TestFramework\MemoryUsageAware;
use Infection\TestFramework\PhpSpec\PhpSpecExtraOptions;
use Infection\TestFramework\PhpUnit\Coverage\CoverageXmlParser;
use Infection\TestFramework\PhpUnit\PhpUnitExtraOptions;
use Infection\TestFramework\TestFrameworkExtraOptions;
use Infection\TestFramework\TestFrameworkTypes;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class InfectionCommand extends BaseCommand
{
    const CI_FLAG_ERROR = 'The minimum required %s percentage should be %s%%, but actual is %s%%. Improve your tests!';

    /**
     * @var ConsoleOutput
     */
    private $consoleOutput;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var bool
     */
    private $skipCoverage;

    protected function configure()
    {
        $this->setName('run')
            ->setDescription('Runs the mutation testing.')
            ->addOption(
                'test-framework',
                null,
                InputOption::VALUE_REQUIRED,
                'Name of the Test framework to use (phpunit, phpspec)',
                ''
            )
            ->addOption(
                'test-framework-options',
                null,
                InputOption::VALUE_REQUIRED,
                'Options to be passed to the test framework'
            )
            ->addOption(
                'threads',
                'j',
                InputOption::VALUE_REQUIRED,
                'Threads count',
                1
            )
            ->addOption(
                'only-covered',
                null,
                InputOption::VALUE_NONE,
                'Mutate only covered by tests lines of code'
            )
            ->addOption(
                'show-mutations',
                's',
                InputOption::VALUE_NONE,
                'Show mutations to the console'
            )
            ->addOption(
                'configuration',
                'c',
                InputOption::VALUE_REQUIRED,
                'Custom configuration file path'
            )
            ->addOption(
                'coverage',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to existing coverage (`xml` and `junit` reports are required)',
                ''
            )
            ->addOption(
                'mutators',
                null,
                InputOption::VALUE_REQUIRED,
                'Specify particular mutators. Example: --mutators=Plus,PublicVisibility'
            )
            ->addOption(
                'filter',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter which files to mutate',
                ''
            )
            ->addOption(
                'formatter',
                null,
                InputOption::VALUE_REQUIRED,
                'Output formatter. Possible values: dot, progress',
                'dot'
            )
            ->addOption(
                'min-msi',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum Mutation Score Indicator (MSI) percentage value. Should be used in CI server.'
            )
            ->addOption(
                'min-covered-msi',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum Covered Code Mutation Score Indicator (MSI) percentage value. Should be used in CI server.'
            )
            ->addOption(
                'log-verbosity',
                null,
                InputOption::VALUE_REQUIRED,
                'Log verbosity level. \'all\' - full logs format, \'default\' - short logs format, \'none\' - no logs.',
                LogVerbosity::NORMAL
            )
            ->addOption(
                'initial-tests-php-options',
                null,
                InputOption::VALUE_REQUIRED,
                'Extra php options for the initial test runner. Will be ignored if --coverage option presented.',
                ''
            )
            ->addOption(
                'ignore-msi-with-no-mutations',
                null,
                InputOption::VALUE_NONE,
                'Ignore MSI violations with zero mutations'
            )
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'Debug mode. Will not clean up Infection temporary folder.'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->hasDebuggerOrCoverageOption()) {
            $this->consoleOutput->logMissedDebuggerOrCoverageOption();

            return 1;
        }

        $container = $this->getContainer();
        $config = $container->get('infection.config');

        $this->includeUserBootstrap($config);

        $testFrameworkKey = $input->getOption('test-framework') ?: $config->getTestFramework();
        $adapter = $container->get('test.framework.factory')->create($testFrameworkKey, $this->skipCoverage);

        LogVerbosity::convertVerbosityLevel($input, $this->consoleOutput);

        $metricsCalculator = new MetricsCalculator();
        $this->registerSubscribers($metricsCalculator, $adapter);

        $processBuilder = new ProcessBuilder($adapter, $config->getProcessTimeout());
        $testFrameworkOptions = $this->getTestFrameworkExtraOptions($testFrameworkKey);

        $initialTestsRunner = new InitialTestsRunner($processBuilder, $this->eventDispatcher);
        $initialTestSuitProcess = $initialTestsRunner->run(
            $testFrameworkOptions->getForInitialProcess(),
            $this->skipCoverage,
            explode(' ', $input->getOption('initial-tests-php-options'))
        );

        if (!$initialTestSuitProcess->isSuccessful()) {
            $this->consoleOutput->logInitialTestsDoNotPass($initialTestSuitProcess, $adapter->getName());

            return 1;
        }

        // We only apply a memory limit if there isn't one set
        if ($adapter instanceof MemoryUsageAware && ini_get('memory_limit') === '-1') {
            $this->applyMemoryLimitFromPhpUnitProcess($initialTestSuitProcess, $adapter);
        }

        $codeCoverageData = $this->getCodeCoverageData($testFrameworkKey);
        $mutationsGenerator = new MutationsGenerator(
            $container->get('src.dirs'),
            $container->get('exclude.paths'),
            $codeCoverageData,
            $container->get('mutators'),
            $this->parseMutators($input->getOption('mutators')),
            $this->eventDispatcher,
            $container->get('parser')
        );

        $mutations = $mutationsGenerator->generate($input->getOption('only-covered'), $input->getOption('filter'));

        $mutationTestingRunner = new MutationTestingRunner(
            $processBuilder,
            $container->get('parallel.process.runner'),
            $container->get('mutant.creator'),
            $this->eventDispatcher,
            $mutations
        );

        $mutationTestingRunner->run(
            (int) $this->input->getOption('threads'),
            $codeCoverageData,
            $testFrameworkOptions->getForMutantProcess()
        );

        return $this->hasRunPassedConstraints($metricsCalculator);
    }

    private function includeUserBootstrap(InfectionConfig $config)
    {
        $bootstrap = $config->getBootstrap();
        if ($bootstrap) {
            if (!file_exists($bootstrap)) {
                throw LocatorException::fileOrDirectoryDoesNotExist($bootstrap);
            }

            require_once $bootstrap;
        }
    }

    private function getOutputFormatter(): OutputFormatter
    {
        if ($this->input->getOption('formatter') === 'progress') {
            return new ProgressFormatter(new ProgressBar($this->output));
        }

        if ($this->input->getOption('formatter') === 'dot') {
            return new DotFormatter($this->output);
        }

        throw new \InvalidArgumentException('Incorrect formatter. Possible values: "dot", "progress"');
    }

    private function applyMemoryLimitFromPhpUnitProcess(Process $process, MemoryUsageAware $adapter)
    {
        if (\PHP_SAPI == 'phpdbg') {
            // Under phpdbg we're using a system php.ini, can't add a memory limit there
            return;
        }

        $tempConfigPath = \php_ini_loaded_file();

        if (empty($tempConfigPath) || !file_exists($tempConfigPath) || !is_writable($tempConfigPath)) {
            // Cannot add a memory limit: there is no php.ini file or it is not writable
            return;
        }

        $memoryLimit = $adapter->getMemoryUsed($process->getOutput());

        if ($memoryLimit < 0) {
            // Cannot detect memory used, not setting any limits
            return;
        }

        /*
         * Since we know how much memory the initial test suite used,
         * and only if we know, we can enforce a memory limit upon all
         * mutation processes. Limit is set to be twice the known amount,
         * because if we know that a normal test suite used X megabytes,
         * if a mutants uses a lot more, this is a definite error.
         *
         * By default we let a mutant process use twice as much more
         * memory as an initial test suite consumed.
         */
        $memoryLimit *= 2;

        file_put_contents($tempConfigPath, PHP_EOL . sprintf('memory_limit = %dM', $memoryLimit), FILE_APPEND);
    }

    private function registerSubscribers(
        MetricsCalculator $metricsCalculator,
        AbstractTestFrameworkAdapter $testFrameworkAdapter
    ) {
        foreach ($this->getSubscribers($metricsCalculator, $testFrameworkAdapter) as $subscriber) {
            $this->eventDispatcher->addSubscriber($subscriber);
        }
    }

    private function getSubscribers(
        MetricsCalculator $metricsCalculator,
        AbstractTestFrameworkAdapter $testFrameworkAdapter
    ): array {
        $subscribers = [
            new InitialTestsConsoleLoggerSubscriber($this->output, $testFrameworkAdapter),
            new MutationGeneratingConsoleLoggerSubscriber($this->output),
            new MutantCreatingConsoleLoggerSubscriber($this->output),
            new MutationTestingConsoleLoggerSubscriber(
                $this->output,
                $this->getOutputFormatter(),
                $metricsCalculator,
                $this->getContainer()->get('diff.colorizer'),
                $this->input->getOption('show-mutations')
            ),
            new MutationTestingResultsLoggerSubscriber(
                $this->output,
                $this->getContainer()->get('infection.config'),
                $metricsCalculator,
                $this->getContainer()->get('filesystem'),
                $this->input->getOption('log-verbosity'),
                (bool) $this->input->getOption('debug')
            ),
        ];

        if (!$this->input->getOption('debug')) {
            $subscribers[] = new CleanUpAfterMutationTestingFinishedSubscriber(
                $this->getContainer()->get('filesystem'),
                $this->getContainer()->get('tmp.dir')
            );
        }

        return $subscribers;
    }

    private function getCodeCoverageData(string $testFrameworkKey): CodeCoverageData
    {
        $coverageDir = $this->getContainer()->get(sprintf('coverage.dir.%s', $testFrameworkKey));
        $testFileDataProviderServiceId = sprintf('test.file.data.provider.%s', $testFrameworkKey);
        $testFileDataProviderService = $this->getContainer()->has($testFileDataProviderServiceId)
            ? $this->getContainer()->get($testFileDataProviderServiceId)
            : null;

        return new CodeCoverageData($coverageDir, new CoverageXmlParser($coverageDir), $testFrameworkKey, $testFileDataProviderService);
    }

    private function parseMutators(string $mutators = null): array
    {
        if ($mutators === null) {
            return [];
        }

        $trimmedMutators = trim($mutators);

        if ($trimmedMutators === '') {
            throw InvalidOptionException::withMessage('The "--mutators" option requires a value.');
        }

        return explode(',', $mutators);
    }

    private function getTestFrameworkExtraOptions(string $testFrameworkKey): TestFrameworkExtraOptions
    {
        $extraOptions = $this->input->getOption('test-framework-options');

        return TestFrameworkTypes::PHPUNIT === $testFrameworkKey
            ? new PhpUnitExtraOptions($extraOptions)
            : new PhpSpecExtraOptions($extraOptions);
    }

    /**
     * Run configuration command if config does not exist
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @throws InfectionException
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $locator = $this->getContainer()->get('locator');

        if ($customConfigPath = $input->getOption('configuration')) {
            $locator->locate($customConfigPath);
        } else {
            $this->runConfigurationCommand($locator);
        }

        $this->consoleOutput = new ConsoleOutput($this->getApplication()->getIO());
        $this->skipCoverage = \strlen(trim($input->getOption('coverage'))) > 0;
        $this->eventDispatcher = $this->getContainer()->get('dispatcher');
    }

    private function hasDebuggerOrCoverageOption(): bool
    {
        return $this->skipCoverage
            || \PHP_SAPI === 'phpdbg'
            || \extension_loaded('xdebug')
            || XdebugHandler::getSkippedVersion()
            || $this->isXdebugIncludedInInitialTestPhpOptions();
    }

    private function runConfigurationCommand(Locator $locator)
    {
        try {
            $locator->locateAnyOf(InfectionConfig::POSSIBLE_CONFIG_FILE_NAMES);
        } catch (\Exception $e) {
            $configureCommand = $this->getApplication()->find('configure');

            $args = [
                '--test-framework' => $this->input->getOption('test-framework'),
            ];

            $newInput = new ArrayInput($args);
            $newInput->setInteractive($this->input->isInteractive());
            $result = $configureCommand->run($newInput, $this->output);

            if ($result !== 0) {
                throw ConfigurationException::configurationAborted();
            }
        }
    }

    private function isXdebugIncludedInInitialTestPhpOptions(): bool
    {
        return (bool) preg_match(
            '/(zend_extension\s*=.*xdebug.*)/mi',
            $this->input->getOption('initial-tests-php-options')
        );
    }

    /**
     * Checks if the infection run passed the constraints like min msi & covered msi.
     *
     * @param MetricsCalculator $metricsCalculator
     *
     * @return int
     */
    private function hasRunPassedConstraints(MetricsCalculator $metricsCalculator): int
    {
        if ($this->input->getOption('ignore-msi-with-no-mutations') && $metricsCalculator->getTotalMutantsCount() === 0) {
            return 0;
        }

        if ($this->hasBadMsi($metricsCalculator)) {
            $this->consoleOutput->logBadMsiErrorMessage($metricsCalculator, (float) $this->input->getOption('min-msi'));

            return 1;
        }

        if ($this->hasBadCoveredMsi($metricsCalculator)) {
            $this->consoleOutput->logBadCoveredMsiErrorMessage(
                $metricsCalculator,
                (float) $this->input->getOption('min-covered-msi')
            );

            return 1;
        }

        return 0;
    }

    private function hasBadMsi(MetricsCalculator $metricsCalculator): bool
    {
        $minMsi = (float) $this->input->getOption('min-msi');

        return $minMsi && ($metricsCalculator->getMutationScoreIndicator() < $minMsi);
    }

    private function hasBadCoveredMsi(MetricsCalculator $metricsCalculator): bool
    {
        $minCoveredMsi = (float) $this->input->getOption('min-covered-msi');

        return $minCoveredMsi && ($metricsCalculator->getCoveredCodeMutationScoreIndicator() < $minCoveredMsi);
    }
}
