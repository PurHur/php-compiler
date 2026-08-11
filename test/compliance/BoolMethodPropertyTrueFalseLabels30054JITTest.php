<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: method/property on true|false use zend_zval_value_name (#30054).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class BoolMethodPropertyTrueFalseLabels30054JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'bool_method_property_true_false_labels.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/bool_method_property_true_false_labels.phpt',
            'bool_method_property_true_false_labels.phpt'
        );
        yield 'scalar_method_call_error.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/scalar_method_call_error.phpt',
            'scalar_method_call_error.phpt'
        );
        yield 'nullsafe_method_nonobject_error.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/nullsafe_method_nonobject_error.phpt',
            'nullsafe_method_nonobject_error.phpt'
        );
        yield 'nullsafe_prop_scalar_warns.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/nullsafe_prop_scalar_warns.phpt',
            'nullsafe_prop_scalar_warns.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
