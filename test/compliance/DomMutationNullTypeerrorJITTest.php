<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DOMNode mutation/importNode null TypeError text (#30410).
 *
 * @group llvm
 * @group jit
 */
final class DomMutationNullTypeerrorJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_mutation_null_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_mutation_null_typeerror.phpt',
            'dom_mutation_null_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
