<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmEnv;
use PHPCompiler\ext\standard\VmEnvEnvironNative;
use PHPUnit\Framework\TestCase;

/**
 * @group vm
 */
final class VmEnvEnvironNativeTest extends TestCase
{
    public function testEnumerateReturnsPathOrHome(): void
    {
        $all = VmEnvEnvironNative::enumerate();
        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
        $this->assertTrue(
            \array_key_exists('PATH', $all) || \array_key_exists('HOME', $all),
            'expected PATH or HOME in process environment'
        );
    }

    public function testGetAllEnvironmentHonorsPutenvOverlay(): void
    {
        $key = 'PHP_COMPILER_GETENV_ALL_TEST_'.getmypid();
        $this->assertTrue(VmEnv::putenv($key.'=overlay'));
        $ht = VmEnv::getAllEnvironmentTable();
        $bucket = $ht->find($key);
        $this->assertNotNull($bucket);
        $this->assertSame('overlay', $bucket->toString());
        VmEnv::putenv($key);
    }
}
