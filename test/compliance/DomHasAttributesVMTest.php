<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: hasAttributes xmlNode->properties (ext/dom/node.c) (#32458).
 */
final class DomHasAttributesVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_hasattributes.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_hasattributes.phpt',
            'dom_hasattributes.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
