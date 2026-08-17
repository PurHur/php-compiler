<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: static property write inside closure persists on read-back (#31965).
 */
final class StaticPropertyClosureWriteRead31965VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'static_property_closure_write_read_31965.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_property_closure_write_read_31965.phpt',
            'static_property_closure_write_read_31965.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
