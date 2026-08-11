<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: property ++/-- on true|false use increment/decrement + true/false (#30075).
 */
require_once __DIR__.'/../BaseTest.php';

final class BoolPropIncDecTrueFalseLabels30075VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'bool_prop_incdec_true_false_labels.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/bool_prop_incdec_true_false_labels.phpt',
            'bool_prop_incdec_true_false_labels.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
