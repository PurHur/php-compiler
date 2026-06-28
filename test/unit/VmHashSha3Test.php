<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\ext\standard\VmHashNative;
use PHPCompiler\ext\standard\VmHashSha3Pure;
use PHPUnit\Framework\TestCase;

/** SHA-3 digests via VmHashSha3Pure (#12903). */
final class VmHashSha3Test extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function sha3Provider(): iterable
    {
        yield 'sha3-224' => ['sha3-224', 'test'];
        yield 'sha3-256' => ['sha3-256', 'test'];
        yield 'sha3-384' => ['sha3-384', 'test'];
        yield 'sha3-512' => ['sha3-512', 'test'];
        yield 'empty' => ['sha3-256', ''];
    }

    /** @dataProvider sha3Provider */
    public function testVmHashNativeMatchesZend(string $algo, string $data): void
    {
        $expected = \hash($algo, $data);
        $this->assertSame($expected, VmHashNative::hash($algo, $data));
        $this->assertSame($expected, VmHash::hash($algo, $data));
    }

    public function testHashHmacSha3MatchesZend(): void
    {
        $expected = \hash_hmac('sha3-256', 'payload', 'secret');
        $this->assertSame($expected, VmHashNative::hashHmac('sha3-256', 'payload', 'secret'));
    }

    public function testPureSha3MatchesZend(): void
    {
        $expected = \hash('sha3-256', 'probe');
        $bytes = VmHashSha3Pure::sha3_256('probe');
        $actual = \bin2hex(\pack('C*', ...$bytes));
        $this->assertSame($expected, $actual);
    }
}
