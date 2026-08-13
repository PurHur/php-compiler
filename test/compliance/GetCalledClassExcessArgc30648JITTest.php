<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: get_called_class() excess argc → ArgumentCountError (#30648). */
final class GetCalledClassExcessArgc30648JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_get_called_class_30648_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_get_called_class_30648_jit.phpt',
            'excess_argc_get_called_class_30648_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
