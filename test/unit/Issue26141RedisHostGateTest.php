<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** #26141 — VM redis introspection matches host phpredis on reference profile. */
final class Issue26141RedisHostGateTest extends TestCase
{
    public function testVmMatchesHostRedisAdvertisement(): void
    {
        $repro = __DIR__.'/../repro/maintainer_gap_redis_host_gate.php';
        self::assertFileExists($repro);

        $hostOut = [];
        exec('php '.escapeshellarg($repro).' 2>&1', $hostOut, $hostCode);
        self::assertSame(0, $hostCode, implode("\n", $hostOut));

        $vmOut = [];
        exec('php bin/vm.php '.escapeshellarg($repro).' 2>&1', $vmOut, $vmCode);
        self::assertSame(0, $vmCode, implode("\n", $vmOut));

        self::assertSame($hostOut, $vmOut, 'VM redis advertisement must match host Zend');
    }
}
