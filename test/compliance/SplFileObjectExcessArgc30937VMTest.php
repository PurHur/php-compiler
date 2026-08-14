<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SplFileObject/FilesystemIterator excess argc (#30937). */
final class SplFileObjectExcessArgc30937VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_splfileobject_30937.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_splfileobject_30937.phpt',
            'excess_argc_splfileobject_30937.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
