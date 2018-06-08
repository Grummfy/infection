<?php
/**
 * Copyright © 2017-2018 Maks Rafalko
 *
 * License: https://opensource.org/licenses/BSD-3-Clause New BSD License
 */

declare(strict_types=1);

namespace Infection\TestFramework\Atoum\Config;

class InitalConfiguration
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

    public function toContent(): string
    {
        // TODO
        return '';
    }
}
