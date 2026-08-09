<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance: #[\Deprecated] class-const / enum-case use-site line (#29381). */
final class DeprecatedConstEnumUseLineVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'deprecated_const_use_line.phpt',
            'deprecated_enum_case_use_line.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
