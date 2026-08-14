<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mb_str_pad() undefined on 8.2 reference — not merely hidden from function_exists (#31174). */
final class MbStrPadPhantomCallVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_str_pad_phantom.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_str_pad_phantom.phpt',
            'mb_str_pad_phantom.phpt'
        );
        yield 'mb_str_pad_phantom_profile82.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/mb_str_pad_phantom_profile82.phpt',
            'mb_str_pad_phantom_profile82.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
