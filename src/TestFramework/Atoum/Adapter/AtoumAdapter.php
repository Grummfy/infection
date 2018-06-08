<?php
/**
 * Copyright © 2017-2018 Maks Rafalko
 *
 * License: https://opensource.org/licenses/BSD-3-Clause New BSD License
 */

declare(strict_types=1);

namespace Infection\TestFramework\Atoum\Adapter;

use Infection\TestFramework\AbstractTestFrameworkAdapter;
use Infection\TestFramework\MemoryUsageAware;

/**
 * @internal
 */
final class AtoumAdapter extends AbstractTestFrameworkAdapter implements MemoryUsageAware
{
    const REGEXP_ERROR = '/There (is|are) \d+ (error|failure)s?:/';
    const REGEXP_SUCCESS = '/Success \(.*\)!/';

    public function testsPass(string $output): bool
    {
        if (preg_match(self::REGEXP_ERROR, $output)) {
            return false;
        }

        if (preg_match(self::REGEXP_SUCCESS, $output)) {
            return true;
        }

        // not sure about the status => failed
        return false;
    }

    public function getMemoryUsed(string $output): float
    {
        if (preg_match('/Total tests memory usage: (\d+(?:\.\d+)) Mb./', $output, $match)) {
            return (float) $match[1];
        }

        return -1;
    }

    public function getName(): string
    {
        return 'atoum';
    }
}
