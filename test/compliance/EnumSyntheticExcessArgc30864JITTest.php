<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: Enum cases/from/tryFrom excess argc → ArgumentCountError (#30864). */
final class EnumSyntheticExcessArgc30864JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_enum_synthetic_30864_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_enum_synthetic_30864_jit.phpt',
            'excess_argc_enum_synthetic_30864_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
