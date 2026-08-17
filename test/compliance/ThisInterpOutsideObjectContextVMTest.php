<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: "$this" / "{$this}" / heredoc outside object context throws Error (#31728).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class ThisInterpOutsideObjectContextVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'this_interp_outside_object_context.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/this_interp_outside_object_context.phpt',
            'this_interp_outside_object_context.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
