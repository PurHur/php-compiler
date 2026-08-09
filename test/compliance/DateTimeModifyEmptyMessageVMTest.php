<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: DateTime(Immutable)::modify("") Empty string message on PROFILE=8.4 (#29301).
 *
 * Dedicated suite so --ENV-- PROFILE is applied via bin/vm.php (not host PHPUnit .phpt).
 */
final class DateTimeModifyEmptyMessageVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_modify_empty_message_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetime_modify_empty_message_forward84.phpt',
            'datetime_modify_empty_message_forward84.phpt'
        );
    }

    public function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
