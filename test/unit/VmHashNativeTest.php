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
        $this->assertDoesNotMatchRegularExpression(
            '/\\\\hash_pbkdf2\s*\(/',
            $src
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\\\\hash_hkdf\s*\(/',
            $src
        );
    }

    public function testHashHkdfMatchesZend(): void
    {
        $this->assertSame(
            \hash_hkdf('sha256', 'key', 16, 'info', 'salt'),
            VmHashNative::hashHkdf('sha256', 'key', 16, 'info', 'salt')
        );
        $this->assertSame(
            \hash_hkdf('sha256', 'key', 32),
            VmHashNative::hashHkdf('sha256', 'key', 32)
        );
        $this->assertSame(
            \hash_hkdf('sha256', 'key', 0),
            VmHashNative::hashHkdf('sha256', 'key', 0)
        );
    }

    public function testHashPbkdf2MatchesZend(): void
    {
        $expected = \hash_pbkdf2('sha256', 'password', 'salt', 1000, 32, true);
        $this->assertSame($expected, VmHashNative::hashPbkdf2('sha256', 'password', 'salt', 1000, 32, true));
        $this->assertSame(
            \hash_pbkdf2('sha256', 'password', 'salt', 1000, 32, false),
            VmHashNative::hashPbkdf2('sha256', 'password', 'salt', 1000, 32, false)
        );
        $this->assertSame(
            \hash_pbkdf2('sha1', 'password', 'salt', 1000, 20, false),
            VmHashNative::hashPbkdf2('sha1', 'password', 'salt', 1000, 20, false)
        );
        $this->assertSame(
            \hash_pbkdf2('sha256', 'pass', 'salt', 1, 8),
            VmHashNative::hashPbkdf2('sha256', 'pass', 'salt', 1, 8)
        );
    }

    public function testIncrementalCopyDoesNotUseHostJson(): void
    {
        $root = \dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/VmHashNative.php');
        $this->assertStringNotContainsString('\\json_decode', $src);
        $this->assertStringNotContainsString('\\json_encode', $src);
    }

    public function testIncrementalCopyIsolatesHashState(): void
    {
        $ctx = VmHashNative::incrementalCreate(1);
        VmHashNative::incrementalUpdate(1, $ctx, 'mutate-original');
        $work = VmHashNative::incrementalCopy($ctx);
        VmHashNative::incrementalUpdate(1, $work, '-copy-only');
        $this->assertSame(
            VmHashNative::incrementalFinal(1, $ctx),
            VmHashNative::hash('sha256', 'mutate-original')
        );
        $this->assertSame(
            VmHashNative::incrementalFinal(1, $work),
            VmHashNative::hash('sha256', 'mutate-original-copy-only')
        );
    }
}
