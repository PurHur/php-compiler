<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: unimplemented abstract parent Fatal cites Parent::method via eval (#30022).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class AbstractParentMethodCiteEval30022VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'abstract_parent_method_cite_eval.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/abstract_parent_method_cite_eval.phpt',
            'abstract_parent_method_cite_eval.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
