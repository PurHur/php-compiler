<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ReflectionClass::getConstants() on DateTime builtins (#30887).
 */
final class ReflectionBuiltinGetConstants30887JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'reflection_class_builtin_get_constants_30887_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/reflection_class_builtin_get_constants_30887.phpt',
            'reflection_class_builtin_get_constants_30887.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
