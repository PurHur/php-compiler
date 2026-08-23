<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: strstr Reflection needle string + before_needle bool optional (#25759).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class StrstrReflection25759VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'strstr_reflection_25759.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/strstr_reflection_25759.phpt',
            'strstr_reflection_25759.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
