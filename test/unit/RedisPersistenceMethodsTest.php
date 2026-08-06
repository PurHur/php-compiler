<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\redis\RedisExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** Redis persistence/wait admin method registration (#28117). */
final class RedisPersistenceMethodsTest extends TestCase
{
    private ?string $profilePrev = null;

    protected function setUp(): void
    {
        parent::setUp();
        $prev = getenv('PHP_COMPILER_PROFILE');
        $this->profilePrev = false === $prev ? null : $prev;
        putenv('PHP_COMPILER_PROFILE=8.4');
        if (!RedisExtensionPolicy::advertisesExtension()) {
            self::markTestSkipped('redis withheld on this profile');
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

    public function test_redis_persistence_methods_registered(): void
    {
        $runtime = new Runtime();
        $entry = $runtime->vmContext->classes['redis'];
        $expected = [
            'save' => 'save',
            'bgsave' => 'bgSave',
            'lastsave' => 'lastSave',
            'wait' => 'wait',
            'waitaof' => 'waitaof',
            'bgrewriteaof' => 'bgrewriteaof',
        ];
        foreach ($expected as $lc => $display) {
            self::assertArrayHasKey($lc, $entry->methods, $display);
            self::assertSame($display, $entry->methodNames[$lc]);
        }
    }
}
