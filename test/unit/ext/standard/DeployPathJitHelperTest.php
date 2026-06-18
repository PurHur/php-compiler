<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPUnit\Framework\TestCase;

/** @group unit */
final class DeployPathJitHelperTest extends TestCase
{
    public function testResolveUsesFallbackWhenEnvUnset(): void
    {
        $prev = getenv('PHPC_DEPLOY_ROOT');
        putenv('PHPC_DEPLOY_ROOT');
        try {
            $this->assertSame(
                '/var/fallback',
                DeployPathJitHelper::resolve('src', '/var/fallback')
            );
        } finally {
            if (false === $prev) {
                putenv('PHPC_DEPLOY_ROOT');
            } else {
                putenv('PHPC_DEPLOY_ROOT='.$prev);
            }
        }
    }

    public function testResolveJoinsUnderDeployRoot(): void
    {
        $prev = getenv('PHPC_DEPLOY_ROOT');
        putenv('PHPC_DEPLOY_ROOT=/tmp/deploy-root');
        try {
            $this->assertSame(
                '/tmp/deploy-root/src',
                DeployPathJitHelper::resolve('src', '/var/fallback')
            );
            $this->assertSame(
                '/tmp/deploy-root',
                DeployPathJitHelper::resolve('', '/var/fallback')
            );
        } finally {
            if (false === $prev) {
                putenv('PHPC_DEPLOY_ROOT');
            } else {
                putenv('PHPC_DEPLOY_ROOT='.$prev);
            }
        }
    }
}
