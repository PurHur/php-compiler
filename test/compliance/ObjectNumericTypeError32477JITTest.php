<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: native-object unary +/- and object⊙int TypeError (#32477).
 *
 * @group llvm
 */
final class ObjectNumericTypeError32477JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'object_numeric_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/object_numeric_typeerror.phpt',
            'object_numeric_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
