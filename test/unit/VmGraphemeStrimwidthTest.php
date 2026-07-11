<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\VmGrapheme;
use PHPUnit\Framework\TestCase;

/** @covers issue #9793 */
final class VmGraphemeStrimwidthTest extends TestCase
{
    public function testStrimwidthJapaneseWithEncoding(): void
    {
        $result = VmGrapheme::strimwidth('日本語テスト', 0, 4, 'UTF-8');
        $this->assertSame('日本', $result);
    }

    public function testStrimwidthJapaneseWithoutEncoding(): void
    {
        $result = VmGrapheme::strimwidth('こんにちは', 0, 3);
        $this->assertIsString($result);
        $this->assertLessThan(\strlen('こんにちは'), \strlen($result));
    }

    public function testStrimwidthInvalidEncodingThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('grapheme_strimwidth(): Argument #4 ($encoding) must be a valid encoding');
        VmGrapheme::strimwidth('こんにちは', 0, 3, '...');
    }

    public function testStrimwidthAsciiNoTrim(): void
    {
        $this->assertSame('hello', VmGrapheme::strimwidth('hello', 0, 10));
    }

    public function testStrimwidthInvalidUtf8ReturnsFalse(): void
    {
        $this->assertFalse(VmGrapheme::strimwidth("\xFF", 0, 1));
    }

    public function testGraphemeStrimwidthAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/grapheme_strimwidth_literals.php';
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for grapheme_strimwidth probe (#9793)'
        );
    }
}
