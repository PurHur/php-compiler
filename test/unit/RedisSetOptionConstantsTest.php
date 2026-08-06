<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\redis\RedisConstants;
use PHPCompiler\ext\redis\RedisExtensionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Redis OPT_/SERIALIZER_ declared casing + setOption/getOption (#28099 / #25929).
 */
final class RedisSetOptionConstantsTest extends TestCase
{
    private ?string $profilePrev = null;

    protected function setUp(): void
    {
        parent::setUp();
        $prev = getenv('PHP_COMPILER_PROFILE');
        $this->profilePrev = false === $prev ? null : $prev;
        putenv('PHP_COMPILER_PROFILE=8.5');
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

    public function test_redis_option_constants_use_declared_casing(): void
    {
        $runtime = new Runtime();
        $entry = $runtime->vmContext->classes['redis'];
        self::assertArrayHasKey('OPT_SERIALIZER', $entry->constants);
        self::assertArrayHasKey('OPT_PREFIX', $entry->constants);
        self::assertArrayHasKey('SERIALIZER_PHP', $entry->constants);
        self::assertArrayHasKey('MULTI', $entry->constants);
        self::assertArrayHasKey('PIPELINE', $entry->constants);
        self::assertArrayNotHasKey('opt_serializer', $entry->constants);
        self::assertSame(RedisConstants::OPT_SERIALIZER, $entry->constants['OPT_SERIALIZER']->toInt());
        self::assertSame(RedisConstants::SERIALIZER_PHP, $entry->constants['SERIALIZER_PHP']->toInt());
        self::assertSame('OPT_SERIALIZER', $entry->constNames['OPT_SERIALIZER']);
        self::assertTrue(\PHPCompiler\ext\standard\VmConstants::constantDefined(
            $runtime->vmContext,
            'Redis::OPT_SERIALIZER'
        ));
        $opt = \PHPCompiler\ext\standard\VmConstants::constantLookup(
            $runtime->vmContext,
            'Redis::OPT_SERIALIZER'
        );
        self::assertNotNull($opt);
        self::assertSame(RedisConstants::OPT_SERIALIZER, $opt->toInt());
        self::assertArrayHasKey('setoption', $entry->methods);
        self::assertArrayHasKey('getoption', $entry->methods);
        self::assertSame('setOption', $entry->methodNames['setoption']);
        self::assertSame('getOption', $entry->methodNames['getoption']);
    }
}
