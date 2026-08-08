<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for #29095 named/unpack missing required → Argument #N not passed.
 *
 * Isolated provider — avoids full VMTest data-provider walk for a one-case lock.
 */
final class NamedArgMissingRequiredNotPassedVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'named_arg_missing_required_not_passed.phpt';
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
