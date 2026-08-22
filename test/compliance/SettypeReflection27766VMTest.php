<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: settype Reflection mixed &$var + bool return (#27766).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class SettypeReflection27766VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'settype_reflection_mixed_27766.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/settype_reflection_mixed_27766.phpt',
            'settype_reflection_mixed_27766.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
