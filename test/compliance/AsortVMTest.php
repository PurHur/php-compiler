<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for asort(). */
final class AsortVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (['asort.phpt', 'asort_int.phpt', 'asort_natural.phpt'] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/stdlib/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
