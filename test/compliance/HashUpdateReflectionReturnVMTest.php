<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for hash_update Reflection return true under PROFILE≥8.4 (#28742). */
final class HashUpdateReflectionReturnVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'hash_update_reflection.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/hash/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
