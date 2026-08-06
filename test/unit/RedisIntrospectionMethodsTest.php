<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\redis\RedisExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** Redis connection introspection method registration (#28116). */
final class RedisIntrospectionMethodsTest extends TestCase
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

    public function test_redis_introspection_methods_registered(): void
    {
        $runtime = new Runtime();
        $entry = $runtime->vmContext->classes['redis'];
        $expected = [
            'gethost' => 'getHost',
            'getport' => 'getPort',
            'getdbnum' => 'getDBNum',
            'gettimeout' => 'getTimeout',
            'getreadtimeout' => 'getReadTimeout',
            'getpersistentid' => 'getPersistentID',
            'getauth' => 'getAuth',
            'getlasterror' => 'getLastError',
            'clearlasterror' => 'clearLastError',
            'getmode' => 'getMode',
        ];
        foreach ($expected as $lc => $display) {
            self::assertArrayHasKey($lc, $entry->methods, $display);
            self::assertSame($display, $entry->methodNames[$lc]);
        }
    }
}
