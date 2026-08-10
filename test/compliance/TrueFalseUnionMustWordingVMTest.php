<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * Dedicated provider — slash-free data-set name so --filter works (#29961).
 * Guards Zend wording: true|false fatal uses "bool must be used instead".
 */
final class TrueFalseUnionMustWordingVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'true_false_union_type.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/true_false_union_type.phpt',
            'true_false_union_type.phpt'
        );
        yield 'false_true_union_type.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/false_true_union_type.phpt',
            'false_true_union_type.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}

