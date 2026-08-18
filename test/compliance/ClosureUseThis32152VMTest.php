<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: closure use($this) is Zend compile fatal (#32152, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ClosureUseThis32152VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'closure_use_this.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_use_this.phpt',
            'closure_use_this.phpt'
        );
        yield 'closure_use_this_method.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_use_this_method.phpt',
            'closure_use_this_method.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
