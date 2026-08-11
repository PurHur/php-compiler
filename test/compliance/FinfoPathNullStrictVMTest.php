<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: finfo_file/buffer(null) TypeError under strict_types (#30259). */
final class FinfoPathNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'finfo_path_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/fileinfo/finfo_path_null_strict.phpt',
            'finfo_path_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
