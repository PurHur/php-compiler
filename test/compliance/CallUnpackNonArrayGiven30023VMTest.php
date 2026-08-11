<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: call unpack TypeError appends ", <type> given" under PROFILE≥8.4 (#30023).
 */
require_once __DIR__.'/../BaseTest.php';

final class CallUnpackNonArrayGiven30023VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'call_unpack_non_array_given_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/call_unpack_non_array_given_84.phpt',
            'call_unpack_non_array_given_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
