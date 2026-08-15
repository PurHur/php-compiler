<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOM residual excess argc → ArgumentCountError (#31251). */
final class DomResidualExcessArgc31251VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dom_residual_31251.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/excess_argc_dom_residual_31251.phpt',
            'excess_argc_dom_residual_31251.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
