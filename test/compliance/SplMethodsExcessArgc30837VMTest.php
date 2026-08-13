<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ArrayObject/SplFileInfo/DirectoryIterator excess argc → ArgumentCountError (#30837). */
final class SplMethodsExcessArgc30837VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_spl_methods_30837.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_spl_methods_30837.phpt',
            'excess_argc_spl_methods_30837.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
