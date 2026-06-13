<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class SidecarPathRemapTest extends TestCase
{
    public function testResolveLeavesExistingPathUntouched(): void
    {
        $path = sys_get_temp_dir().'/phpc_sidecar_remap_'.getmypid();
        file_put_contents($path, 'ok');
        try {
            $this->assertSame($path, SidecarPathRemap::resolve($path));
        } finally {
            @unlink($path);
        }
    }

    public function testResolveDockerBuildPrefixWithRepoRoot(): void
    {
        $root = dirname(__DIR__, 2);
        $blob = $root.'/build/.m3_helloworld_aot_blob';
        if (!is_file($blob)) {
            $this->markTestSkipped('missing build/.m3_helloworld_aot_blob');
        }
        $prev = getenv('PHP_COMPILER_REPO_ROOT');
        putenv('PHP_COMPILER_REPO_ROOT='.$root);
        try {
            $resolved = SidecarPathRemap::resolve('/compiler/build/.m3_helloworld_aot_blob');
            $this->assertSame($blob, $resolved);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_REPO_ROOT');
            } else {
                putenv('PHP_COMPILER_REPO_ROOT='.$prev);
            }
        }
    }
}
