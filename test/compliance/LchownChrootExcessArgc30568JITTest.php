<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: lchown/lchgrp/chroot ArgumentCountError wording (#30568). */
final class LchownChrootExcessArgc30568JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_lchown_chroot_30568_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_lchown_chroot_30568_jit.phpt',
            'excess_argc_lchown_chroot_30568_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
