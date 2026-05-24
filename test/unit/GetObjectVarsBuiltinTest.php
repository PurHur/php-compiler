<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM/JIT compliance for get_object_vars() (issue #1370). */
final class GetObjectVarsBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach (['get_object_vars.phpt', 'get_object_vars_jit.phpt'] as $file) {
            $path = __DIR__.'/../compliance/cases/stdlib/'.$file;
            yield $file => self::parsePHPT($path, $file);
        }
    }
}
