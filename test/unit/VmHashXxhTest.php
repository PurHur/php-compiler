<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\ext\standard\VmHashNative;
use PHPCompiler\ext\standard\VmHashXxh;
use PHPUnit\Framework\TestCase;

/** hash() xxh3/xxh128 via pure PHP VmHashXxhPure (#5165, #12209). */
final class VmHashXxhTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function vectorProvider(): iterable
    {
        yield 'a' => ['xxh3', 'a', 'e6c632b61e964e1f'];
        yield 'abc' => ['xxh3', 'abc', '78af5f94892f3950'];
        yield 'empty' => ['xxh3', '', '2d06800538d394c2'];
        yield 'hello' => ['xxh3', 'hello world', 'd447b1ea40e6988b'];
        yield 'a128' => ['xxh128', 'a', 'a96faf705af16834e6c632b61e964e1f'];
        yield 'abc128' => ['xxh128', 'abc', '06b05ab6733a618578af5f94892f3950'];
    }

    /** @dataProvider vectorProvider */
    public function testVmHashNativeMatchesZend(string $algo, string $data, string $expectedHex): void
    {
        if (!VmHashXxh::available()) {
            self::markTestSkipped('VmHashXxh unavailable');
        }
        $this->assertSame($expectedHex, VmHashNative::hash($algo, $data));
        $this->assertSame($expectedHex, VmHash::hash($algo, $data));
    }

    public function testRawOutputLengths(): void
    {
        if (!VmHashXxh::available()) {
            self::markTestSkipped('VmHashXxh unavailable');
        }
        $this->assertSame(8, \strlen(VmHashNative::hash('xxh3', 'a', true)));
        $this->assertSame(16, \strlen(VmHashNative::hash('xxh128', 'a', true)));
    }

    public function testHashAlgosListsXxh(): void
    {
        $ht = VmHash::algos();
        $names = [];
        for ($i = 0, $n = $ht->getNumElements(); $i < $n; ++$i) {
            $var = $ht->findIndex($i);
            if (null !== $var) {
                $names[] = $var->resolveIndirect()->toString();
            }
        }
        $this->assertContains('xxh3', $names);
        $this->assertContains('xxh128', $names);
    }
}
