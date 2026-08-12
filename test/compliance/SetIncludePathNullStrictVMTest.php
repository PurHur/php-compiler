<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: set_include_path(null) TypeError under strict_types (#30359). */
final class SetIncludePathNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'set_include_path_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/set_include_path_null_strict.phpt',
            'set_include_path_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
