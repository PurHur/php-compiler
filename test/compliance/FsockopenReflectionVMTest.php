<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for fsockopen/pfsockopen Reflection stubs (#28919). */
final class FsockopenReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'fsockopen_reflection_28919.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/stdlib/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
