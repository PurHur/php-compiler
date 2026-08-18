<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: gettext family Zend stub names + named args (#24375).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class GettextNamedParams24375VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gettext_named_params.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gettext_named_params.phpt',
            'gettext_named_params.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
