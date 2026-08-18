<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: createAttribute saveXML xmlNodeDump ` name="value"` (#32351).
 */
final class DomCreateAttribute32351VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_createattribute_savexml.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_createattribute_savexml.phpt',
            'dom_createattribute_savexml.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
