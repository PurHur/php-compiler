<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for uniqid() (#2219). */
final class UniqidVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'uniqid.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/uniqid.phpt',
            'uniqid.phpt'
        );
        yield 'uniqid_coerce.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/uniqid_coerce.phpt',
            'uniqid_coerce.phpt'
        );
        yield 'uniqid_null_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/uniqid_null_forward84.phpt',
            'uniqid_null_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
