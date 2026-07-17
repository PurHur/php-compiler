<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for session_name() / session_id() (#12563, #19968). */
final class SessionNameVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'session_name_empty_warning.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/session_name_empty_warning.phpt',
            'session_name_empty_warning.phpt'
        );
        yield 'session_name_headers_sent.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/session_name_headers_sent.phpt',
            'session_name_headers_sent.phpt'
        );
        yield 'session_id_headers_sent.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/session_id_headers_sent.phpt',
            'session_id_headers_sent.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
