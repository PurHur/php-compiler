<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: replaceData xmlTextReplace (#32391, ext/dom/characterdata.c). */
final class DomReplaceDataVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_replacedata.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_replacedata.phpt',
            'dom_replacedata.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
