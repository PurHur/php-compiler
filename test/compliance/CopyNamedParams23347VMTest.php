<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: copy() Zend stub names from/to/context + named from:/to: (#23347).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class CopyNamedParams23347VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'copy_named_params_23347.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/copy_named_params_23347.phpt',
            'copy_named_params_23347.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
