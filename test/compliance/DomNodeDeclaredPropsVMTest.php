<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOMNode/DOMDocument/DOMXPath declared stub properties (#31753). */
final class DomNodeDeclaredPropsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_node_declared_props.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_node_declared_props.phpt',
            'dom_node_declared_props.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
