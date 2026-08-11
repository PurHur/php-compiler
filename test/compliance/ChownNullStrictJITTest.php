<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: chown/chgrp/lchown/lchgrp(null) TypeError under strict_types (#30167). */
final class ChownNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'chown_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/chown_null_strict_jit.phpt',
            'chown_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
