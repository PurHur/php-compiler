<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: integer `/` always yields float (#31968 remaining zend_div).
 *
 * @group llvm
 */
final class IntDivAlwaysFloat31968JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'int_div_always_float.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/int_div_always_float.phpt',
            'int_div_always_float.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
