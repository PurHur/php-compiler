<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\GetoptEngine;
use PHPUnit\Framework\TestCase;

/** getopt() parser engine (#3251, #9093). */
final class GetoptEngineTest extends TestCase
{
    public function testOptionalLongOptionWithEqualsDoesNotLoop(): void
    {
        $argv = ['script.php', '-a', '-b', 'B', '--long', 'L', '--opt=V', '--', '-x'];
        $restIndex = 0;
        $parsed = GetoptEngine::parse($argv, 'ab:', ['long:', 'opt::'], $restIndex, true);

        self::assertIsArray($parsed);
        self::assertSame(false, $parsed['a']);
        self::assertSame('B', $parsed['b']);
        self::assertSame('L', $parsed['long']);
        self::assertSame('V', $parsed['opt']);
        self::assertSame(8, $restIndex);
    }
}
