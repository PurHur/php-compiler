<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for get_mangled_object_vars() (issue #3497). */
final class GetMangledObjectVarsBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/../compliance/cases/stdlib/get_mangled_object_vars.phpt';
        yield 'get_mangled_object_vars.phpt' => self::parsePHPT($path, 'get_mangled_object_vars.phpt');
    }
}
