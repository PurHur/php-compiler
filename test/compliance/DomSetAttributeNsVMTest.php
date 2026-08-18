<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: setAttributeNS xmlSetNsProp (#32398, ext/dom/element.c). */
final class DomSetAttributeNsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_setattrns.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_setattrns.phpt',
            'dom_setattrns.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
