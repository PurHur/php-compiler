<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ReflectionAttribute/NamedType/ClassConstant/Property excess argc (#30896). */
final class ReflectionAttrExcessArgc30896JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_attr_30896_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_attr_30896_jit.phpt',
            'excess_argc_reflection_attr_30896_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
