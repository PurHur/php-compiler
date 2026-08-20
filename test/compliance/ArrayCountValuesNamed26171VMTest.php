<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array_count_values Reflection + Zend named params (#26171).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ArrayCountValuesNamed26171VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_count_values_named_26171.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_count_values_named_26171.phpt',
            'array_count_values_named_26171.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
