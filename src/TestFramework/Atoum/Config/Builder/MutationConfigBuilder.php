<?php
/**
 * Copyright © 2017-2018 Maks Rafalko
 *
 * License: https://opensource.org/licenses/BSD-3-Clause New BSD License
 */

declare(strict_types=1);

namespace Infection\TestFramework\Atoum\Config\Builder;

use Infection\Mutant\MutantInterface;
use Infection\TestFramework\Config\MutationConfigBuilder as ConfigBuilder;

/**
 * @internal
 */
class MutationConfigBuilder extends ConfigBuilder
{
    /**
     * @var string
     */
    private $tempDirectory;

    /**
     * @var string
     */
    private $projectDir;

    public function __construct(string $tempDirectory, string $projectDir)
    {
        $this->tempDirectory = $tempDirectory;
        $this->projectDir = $projectDir;
    }

    public function build(MutantInterface $mutant): string
    {
        // TODO
        return '';
    }
}
