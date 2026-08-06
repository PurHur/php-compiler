<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for gethostname() (#3465). */
final class GethostnameVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gethostname.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostname.phpt',
            'gethostname.phpt'
        );
        yield 'gethostname_argc.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostname_argc.phpt',
            'gethostname_argc.phpt'
        );
        yield 'gethostname_reflection_return.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gethostname_reflection_return.phpt',
            'gethostname_reflection_return.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
