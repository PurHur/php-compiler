<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: lchown/lchgrp/chroot ArgumentCountError wording (#30568). */
final class LchownChrootExcessArgc30568VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_lchown_chroot_30568.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_lchown_chroot_30568.phpt',
            'excess_argc_lchown_chroot_30568.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
