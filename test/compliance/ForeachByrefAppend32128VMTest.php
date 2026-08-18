<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: foreach-by-ref append during iteration matches Zend (#32128).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ForeachByrefAppend32128VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'foreach_byref_append_32128.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/foreach_byref_append_32128.phpt',
            'foreach_byref_append_32128.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
