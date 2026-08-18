<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: cloneNode + saveXML xmlNodeDump (#32355).
 */
final class DomCloneNode32355VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_clonenode_savexml.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_clonenode_savexml.phpt',
            'dom_clonenode_savexml.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
