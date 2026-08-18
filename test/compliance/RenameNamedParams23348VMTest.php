<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: rename() Zend stub names from/to/context + named from:/to: (#23348).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class RenameNamedParams23348VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'rename_named_params_23348.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/rename_named_params_23348.phpt',
            'rename_named_params_23348.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
