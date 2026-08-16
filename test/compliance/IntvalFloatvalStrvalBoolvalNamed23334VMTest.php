<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: intval/floatval/strval/boolval Reflection + Zend named params (#23334).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class IntvalFloatvalStrvalBoolvalNamed23334VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'intval_floatval_strval_boolval_named_23334.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/intval_floatval_strval_boolval_named_23334.phpt',
            'intval_floatval_strval_boolval_named_23334.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
