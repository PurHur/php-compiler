<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\ext\standard\VmHashNative;
use PHPCompiler\ext\standard\VmHashXxh;
use PHPCompiler\ext\standard\VmHashXxhPure;
use PHPUnit\Framework\TestCase;

/** VmHashXxh — xxh3/xxh128 without libxxhash FFI (#12209). */
final class VmHashXxhRuntimeShrinkTest extends TestCase
{
    public function testVmHashXxhDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmHashXxh.php');
        $this->assertStringContainsString('VmHashXxhPure::xxh3DigestBytes', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('libxxhash', $source);
    }

    public function testVmHashXxhPureDoesNotUseLibxxhashFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmHashXxhPure.php');
        $this->assertStringContainsString('KSECRET', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }

    /** @dataProvider vectorProvider */
    public function testPureDigestMatchesZendWithFfiDisabled(string $algo, string $data, string $expectedHex): void
    {
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmHashXxh::available());
            if ('xxh3' === $algo) {
                $bytes = VmHashXxhPure::xxh3DigestBytes($data);
            } else {
                $bytes = VmHashXxhPure::xxh128DigestBytes($data);
            }
            $this->assertNotNull($bytes);
            $this->assertSame($expectedHex, bin2hex(pack('C*', ...$bytes)));
            $this->assertSame($expectedHex, VmHashNative::hash($algo, $data));
            $this->assertSame($expectedHex, VmHash::hash($algo, $data));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function vectorProvider(): iterable
    {
        yield 'a' => ['xxh3', 'a', 'e6c632b61e964e1f'];
        yield 'abc' => ['xxh3', 'abc', '78af5f94892f3950'];
        yield 'empty' => ['xxh3', '', '2d06800538d394c2'];
        yield 'hello' => ['xxh3', 'hello world', 'd447b1ea40e6988b'];
        yield 'a128' => ['xxh128', 'a', 'a96faf705af16834e6c632b61e964e1f'];
        yield 'abc128' => ['xxh128', 'abc', '06b05ab6733a618578af5f94892f3950'];
        yield 'empty128' => ['xxh128', '', '99aa06d3014798d86001c324468d497f'];
        yield 'hello128' => ['xxh128', 'hello world', 'df8d09e93f874900a99b8775cc15b6c7'];
    }
}
