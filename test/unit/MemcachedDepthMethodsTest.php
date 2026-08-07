<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\memcached\MemcachedExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** Memcached depth method registration (#27874). */
final class MemcachedDepthMethodsTest extends TestCase
{
    private ?string $profilePrev = null;

    protected function setUp(): void
    {
        parent::setUp();
        $prev = getenv('PHP_COMPILER_PROFILE');
        $this->profilePrev = false === $prev ? null : $prev;
        putenv('PHP_COMPILER_PROFILE=8.4');
        if (!MemcachedExtensionPolicy::advertisesExtension()) {
            self::markTestSkipped('memcached withheld on this profile');
        }
    }

    protected function tearDown(): void
    {
        if (null === $this->profilePrev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->profilePrev);
        }
        parent::tearDown();
    }

    public function test_memcached_depth_methods_registered(): void
    {
        $runtime = new Runtime();
        $entry = $runtime->vmContext->classes['memcached'];
        $expected = [
            'add' => 'add',
            'replace' => 'replace',
            'append' => 'append',
            'prepend' => 'prepend',
            'getmulti' => 'getMulti',
            'setmulti' => 'setMulti',
            'deletemulti' => 'deleteMulti',
            'increment' => 'increment',
            'decrement' => 'decrement',
            'cas' => 'cas',
            'flush' => 'flush',
            'touch' => 'touch',
        ];
        foreach ($expected as $lc => $display) {
            self::assertArrayHasKey($lc, $entry->methods, $display);
            self::assertSame($display, $entry->methodNames[$lc]);
        }
    }
}
