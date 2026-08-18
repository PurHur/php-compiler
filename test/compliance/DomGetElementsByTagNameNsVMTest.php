<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: getElementsByTagNameNS live list (#32415, ext/dom/php_dom.c). */
final class DomGetElementsByTagNameNsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_gebtns.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_gebtns.phpt',
            'dom_gebtns.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
