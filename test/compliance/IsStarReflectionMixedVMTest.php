<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for is_* / is_callable Reflection stubs (#28312, #30242). */
final class IsStarReflectionMixedVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'is_star_reflection_mixed.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/stdlib/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
