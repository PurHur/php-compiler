<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: lookupPrefix xmlSearchNsByHref (ext/dom/node.c) (#32493).
 */
final class DomLookupPrefixVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_lookupprefix.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_lookupprefix.phpt',
            'dom_lookupprefix.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
