<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for dirname() wrapper paths (#11026). */
final class DirnameVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dirname_phar_wrapper.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dirname_phar_wrapper.phpt',
            'dirname_phar_wrapper.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
