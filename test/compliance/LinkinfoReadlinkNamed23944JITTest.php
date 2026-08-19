<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: linkinfo/readlink Zend stub names + named args (#23944).
 */
final class LinkinfoReadlinkNamed23944JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'linkinfo_readlink_named_23944.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/linkinfo_readlink_named_23944.phpt',
            'linkinfo_readlink_named_23944.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
