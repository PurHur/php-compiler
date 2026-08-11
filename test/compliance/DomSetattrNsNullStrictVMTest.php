<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOM setIdAttribute / *AttributeNS / getElementsByTagNameNS null under strict_types (#30091). */
final class DomSetattrNsNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_setattr_ns_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_setattr_ns_null_strict.phpt',
            'dom_setattr_ns_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
