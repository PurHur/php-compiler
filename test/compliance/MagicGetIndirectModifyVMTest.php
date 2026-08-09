<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: overloaded __get []=/dim-write is E_NOTICE + continue (#29231, re-#4673). */
final class MagicGetIndirectModifyVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'magic_get_indirect_modify.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/language/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
