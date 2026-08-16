<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: strchr Reflection before_needle bool optional + string|false (#25758).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class StrchrReflection25758VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'strchr_reflection_25758.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/strchr_reflection_25758.phpt',
            'strchr_reflection_25758.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
