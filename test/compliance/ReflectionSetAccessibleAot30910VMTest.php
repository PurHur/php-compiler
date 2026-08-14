<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Reflection setAccessible/getValue/invoke (#30910). */
final class ReflectionSetAccessibleAot30910VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'reflection_setaccessible_aot_30910.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/reflection_setaccessible_aot_30910.phpt',
            'reflection_setaccessible_aot_30910.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
