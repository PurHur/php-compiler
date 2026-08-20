<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for openlog()/syslog()/closelog() (#3676). */
final class SyslogVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'syslog_constants.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/syslog_constants.phpt',
            'syslog_constants.phpt'
        );
        yield 'syslog_call.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/syslog_call.phpt',
            'syslog_call.phpt'
        );
        yield 'openlog_named_flags.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/openlog_named_flags.phpt',
            'openlog_named_flags.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
