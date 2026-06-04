<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHashNative;
use PHPUnit\Framework\TestCase;

/** VM-native hash() / hash_hmac() digests (issue #4790). */
final class VmHashNativeTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function algoDataProvider(): iterable
    {
        yield 'sha256' => ['sha256', 'The quick brown fox jumps over the lazy dog'];
        yield 'sha1' => ['sha1', 'body'];
        yield 'md5' => ['md5', 'hello'];
    }

    /** @dataProvider algoDataProvider */
    public function testHashMatchesZend(string $algo, string $data): void
    {
        $expected = \hash($algo, $data);
        $this->assertSame($expected, VmHashNative::hash($algo, $data));
        $this->assertSame($expected, VmHashNative::hash(\strtoupper($algo), $data));
    }

    /** @dataProvider algoDataProvider */
    public function testHashHmacMatchesZend(string $algo, string $data): void
    {
        $key = 'secret-key';
        $expected = \hash_hmac($algo, $data, $key);
        $this->assertSame($expected, VmHashNative::hashHmac($algo, $data, $key));
        $this->assertSame($expected, VmHashNative::hashHmac(\strtoupper($algo), $data, $key));
    }

    public function testRawOutputMatchesZend(): void
    {
        $data = 'raw-test';
        foreach (['sha256', 'sha1', 'md5'] as $algo) {
            $expected = \hash($algo, $data, true);
            $actual = VmHashNative::hash($algo, $data, true);
            $this->assertSame($expected, $actual);
            $this->assertSame(\strlen($expected), \strlen($actual));

            $expectedHmac = \hash_hmac($algo, $data, 'key', true);
            $actualHmac = VmHashNative::hashHmac($algo, $data, 'key', true);
            $this->assertSame($expectedHmac, $actualHmac);
            $this->assertSame(\strlen($expectedHmac), \strlen($actualHmac));
        }
    }

    public function testUnknownAlgoReturnsFalse(): void
    {
        $this->assertFalse(VmHashNative::hash('sha512', 'data'));
        $this->assertFalse(VmHashNative::hashHmac('sha512', 'data', 'key'));
    }

    public function testVmHashDoesNotCallHostHash(): void
    {
        $root = \dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/VmHash.php');
        $this->assertDoesNotMatchRegularExpression(
            '/\\\\hash\s*\(/',
            $src
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\\\\hash_hmac\s*\(/',
            $src
        );
    }
}
