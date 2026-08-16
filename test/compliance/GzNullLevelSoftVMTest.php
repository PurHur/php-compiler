<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: gzcompress/gzencode/gzdeflate null $level soft Deprecated (#31445).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class GzNullLevelSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gz_null_level_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gz_null_level_soft.phpt',
            'gz_null_level_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
