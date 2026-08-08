<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: __PHP_Incomplete_Class property access warning includes function-name prefix (#29025).
 *
 * Dedicated provider — full VMTest discovery is heavy, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class IncompleteClassAccessWarningPrefixVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'incomplete_class_access_warning_prefix.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/incomplete_class_access_warning_prefix.phpt',
            'incomplete_class_access_warning_prefix.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
