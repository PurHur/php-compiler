<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for localtime Reflection ?int $timestamp = null (#27980). */
final class LocaltimeReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'localtime_reflection_timestamp.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/date/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
