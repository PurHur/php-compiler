<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: DOM mutation/importNode(null) TypeError (#32558 leftover of #30410).
 */
final class DomMutationNullAotVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_mutation_null_aot.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_mutation_null_aot.phpt',
            'dom_mutation_null_aot.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
