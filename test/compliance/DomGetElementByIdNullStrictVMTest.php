<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOMDocument::getElementById(null) TypeError under strict_types (#29942). */
final class DomGetElementByIdNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_getelementbyid_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_getelementbyid_null_strict.phpt',
            'dom_getelementbyid_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
