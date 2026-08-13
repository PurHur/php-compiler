<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: preg_last_error / preg_last_error_msg / zend_version excess argc → ArgumentCountError (#30628). */
final class PregZendVersionExcessArgc30628JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_preg_zend_version_30628_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_preg_zend_version_30628_jit.phpt',
            'excess_argc_preg_zend_version_30628_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
