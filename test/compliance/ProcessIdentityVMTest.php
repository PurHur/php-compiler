<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for process identity builtins (#6119). */
final class ProcessIdentityVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'process_identity.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/process_identity.phpt',
            'process_identity.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
