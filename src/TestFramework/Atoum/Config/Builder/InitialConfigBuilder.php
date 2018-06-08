<?php
/**
 * Copyright © 2017-2018 Maks Rafalko
 *
 * License: https://opensource.org/licenses/BSD-3-Clause New BSD License
 */

declare(strict_types=1);

namespace Infection\TestFramework\Atoum\Config\Builder;

use Infection\TestFramework\Atoum\Config\InitialConfiguration;
use Infection\TestFramework\Config\InitialConfigBuilder as ConfigBuilder;

class InitialConfigBuilder implements ConfigBuilder
{
    /**
     * @var string
     */
    private $tempDirectory;

    /**
     * @var bool
     */
    private $skipCoverage;

    public function __construct(string $tempDirectory, bool $skipCoverage)
    {
        $this->tempDirectory = $tempDirectory;
        $this->skipCoverage = $skipCoverage;
    }

    public function build(): string
    {
        $path = $this->buildPath();

        $config = new InitialConfiguration(
            $this->tempDirectory,
            $this->skipCoverage
        );

        file_put_contents($path, $config->toContent());

        return $path;
    }

    private function buildPath(): string
    {
        return $this->tempDirectory . '/atoum.infection.php';
    }
}
