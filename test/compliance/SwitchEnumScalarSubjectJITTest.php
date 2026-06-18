<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT: switch scalar subject vs enum case label must not match (#8880, zend_operators.c). */
final class SwitchEnumScalarSubjectJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'switch_enum_scalar_subject.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/switch_enum_scalar_subject.phpt',
            'switch_enum_scalar_subject.phpt'
        );
        yield 'switch_enum_scalar_case.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/switch_enum_scalar_case.phpt',
            'switch_enum_scalar_case.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
