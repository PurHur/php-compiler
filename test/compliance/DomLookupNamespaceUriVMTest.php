<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: lookupNamespaceURI / isDefaultNamespace xmlSearchNs (ext/dom/node.c) (#32504).
 */
final class DomLookupNamespaceUriVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_lookupnamespaceuri.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_lookupnamespaceuri.phpt',
            'dom_lookupnamespaceuri.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
