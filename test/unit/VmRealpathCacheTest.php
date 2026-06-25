<?php

declare(strict_types=1);

namespace Test\Unit;

use PHPCompiler\ext\standard\VmRealpathCache;
use PHPCompiler\ext\standard\VmStatCache;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** VmRealpathCache realpath() hook + clearstatcache integration (#3463). */
final class VmRealpathCacheTest extends TestCase
{
    protected function setUp(): void
    {
        VmRealpathCache::reset();
        VmStatCache::reset();
    }

    protected function tearDown(): void
    {
        VmRealpathCache::reset();
        VmStatCache::reset();
    }

    public function testRecordAndGetAfterRealpath(): void
    {
        $path = __DIR__.'/../../composer.json';
        if (!is_file($path)) {
            self::markTestSkipped('composer.json missing');
        }

        $resolved = \PHPCompiler\ext\standard\VmString::realpath($path);
        self::assertIsString($resolved);
        self::assertGreaterThan(0, VmRealpathCache::size());

        $cache = VmRealpathCache::get();
        $cache->iterReset();
        self::assertTrue($cache->iterValid());
    }

    public function testRecordResolvedPathPrefixes(): void
    {
        $dir = \realpath(__DIR__.'/../..');
        if (false === $dir) {
            self::markTestSkipped('repo root missing');
        }

        VmRealpathCache::reset();
        \PHPCompiler\ext\standard\VmString::realpath($dir);
        $cache = VmRealpathCache::get();
        $count = 0;
        $cache->iterReset();
        while ($cache->iterValid()) {
            ++$count;
            $keyVar = $cache->iterCurrentKey();
            self::assertSame(Variable::TYPE_STRING, $keyVar->type);
            self::assertStringStartsWith('/', $keyVar->toString());
            $entryVar = $cache->iterCurrentValue();
            self::assertSame(Variable::TYPE_ARRAY, $entryVar->type);
        }
        self::assertGreaterThanOrEqual(2, $count);
    }

    public function testClearstatcacheClearsRealpathCache(): void
    {
        $path = __DIR__.'/../../composer.json';
        if (!is_file($path)) {
            self::markTestSkipped('composer.json missing');
        }

        \PHPCompiler\ext\standard\VmString::realpath($path);
        self::assertGreaterThan(0, VmRealpathCache::size());
        VmStatCache::clear();
        self::assertSame(0, VmRealpathCache::size());
    }
}
