<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: class_alias() excess argc → ArgumentCountError (#30783). */
final class ClassAliasExcessArgc30783JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_class_alias_30783_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_class_alias_30783_jit.phpt',
            'excess_argc_class_alias_30783_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
