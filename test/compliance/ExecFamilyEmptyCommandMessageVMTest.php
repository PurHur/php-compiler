<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: exec family empty command ValueError "cannot be empty" (#30340, php-src exec.c). */
final class ExecFamilyEmptyCommandMessageVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'exec_family_empty_command_cannot_be_empty.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/exec_family_empty_command_cannot_be_empty.phpt',
            'exec_family_empty_command_cannot_be_empty.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
