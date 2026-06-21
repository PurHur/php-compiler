<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: $_REQUEST still readable in caller after static guard method (#10389). */
final class RequestSecondReadAfterStaticGuardTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'request_second_read_after_static_guard.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/web/request_second_read_after_static_guard.phpt',
            'request_second_read_after_static_guard.phpt'
        );
    }
}
