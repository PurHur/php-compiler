<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOM C14N/lookupNamespaceURI/ctor Reflection stubs (#31849). */
final class DomReflectionC14nCtorStubsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_reflection_c14n_ctor_stubs.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_reflection_c14n_ctor_stubs.phpt',
            'dom_reflection_c14n_ctor_stubs.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
