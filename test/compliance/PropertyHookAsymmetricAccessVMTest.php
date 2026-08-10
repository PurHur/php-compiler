<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: set-only read / backed get-only default write (#18072, #29674). */
final class PropertyHookAsymmetricAccessVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'property_hook_asymmetric_access.phpt';
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
