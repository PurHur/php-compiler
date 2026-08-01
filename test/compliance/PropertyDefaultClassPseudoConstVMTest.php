<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: property defaults fold self/parent/Named::class (#26629, #3803).
 */
final class PropertyDefaultClassPseudoConstVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'property_default_class_pseudo_const.phpt',
            'property_default_class_pseudo_const_ns.phpt',
            'property_default_static_class_reject.phpt',
            'property_default_const_expr.phpt',
            'property_default_array_class_const.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
