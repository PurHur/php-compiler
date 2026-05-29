<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: dynamic class constant fetch Class::{$name} (#3150).
 */
final class ClassConstDynamicVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'class_const_dynamic_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/class_const_dynamic_jit.phpt',
            'class_const_dynamic_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
