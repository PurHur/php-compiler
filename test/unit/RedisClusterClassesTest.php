<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\redis\RedisExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** RedisCluster / RedisArray / RedisClusterException when redis advertised (#28094). */
final class RedisClusterClassesTest extends TestCase
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

    public function test_redis_cluster_array_classes_registered(): void
    {
        $runtime = new Runtime();
        self::assertArrayHasKey('rediscluster', $runtime->vmContext->classes);
        self::assertArrayHasKey('redisarray', $runtime->vmContext->classes);
        self::assertArrayHasKey('redisclusterexception', $runtime->vmContext->classes);
        self::assertSame(
            'runtimeexception',
            $runtime->vmContext->classes['redisclusterexception']->parentLc
        );
        $cluster = $runtime->vmContext->classes['rediscluster'];
        self::assertArrayHasKey('get', $cluster->methods);
        self::assertArrayHasKey('set', $cluster->methods);
        self::assertArrayHasKey('del', $cluster->methods);
        self::assertArrayHasKey('OPT_SLAVE_FAILOVER', $cluster->constants);
        $array = $runtime->vmContext->classes['redisarray'];
        self::assertArrayHasKey('get', $array->methods);
        self::assertArrayHasKey('set', $array->methods);
        self::assertArrayHasKey('del', $array->methods);
        self::assertArrayHasKey('_hosts', $array->methods);
        self::assertArrayHasKey('_target', $array->methods);
    }
}
