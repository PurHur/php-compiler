<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: eval() from an instance method inherits $this (#31902).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class EvalInheritsThisVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'eval_inherits_this.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/eval_inherits_this.phpt',
            'eval_inherits_this.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
