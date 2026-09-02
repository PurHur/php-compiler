<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group unit */
final class BootstrapRequireNativeEmitTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testGen1LinkRefusesSidecarOkWhenRequireNativeEmit(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-loop-gen1-link.sh');
        $this->assertStringContainsString('BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT', $script);
        $this->assertStringContainsString('refusing prelinked sidecar COPY in place of a native emit', $script);
        $this->assertStringContainsString('refusing emit_path=native-prelinked-sidecar', $script);
        $this->assertStringContainsString('#36146', $script);
        $this->assertStringContainsString('BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT:-0}" != "1"', $script);
    }

    public function testCompileInvokeRefusesSidecarWhenRequireNativeEmit(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-resolve-compile-invoke.sh');
        $this->assertStringContainsString('REQUIRE_NATIVE_EMIT=1 — refusing sidecar emit fallback', $script);
        $this->assertStringContainsString('BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT', $script);
        $this->assertStringContainsString('BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT', $script);
    }

    public function testBootstrapLoopProbePropagatesRequireNativeEmitToM3(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-loop-probe.sh');
        $this->assertStringContainsString('export BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT', $script);
    }

    public function testHelloWorldProbeRefusesSidecarWhenRequireNativeEmit(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-helloworld-probe.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT', $script);
        $this->assertStringContainsString('refusing prelinked sidecar COPY as a native emit', $script);
        $this->assertStringContainsString('native-prelinked-sidecar', $script);
    }

    public function testBootstrapLoopProbePassesRequireNativeEmitToGen1Link(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-loop-probe.sh');
        $this->assertStringContainsString('env BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT=', $script);
        $this->assertStringContainsString('sidecar COPY must not count as success (#36146)', $script);
        $this->assertStringContainsString('m4_gen1_log_emit_path_sidecar', $script);
    }

    public function testNorthStar4VerifyDefaultsRequireNativeEmit(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/north-star4-verify.sh');
        $this->assertStringContainsString('BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT="${BOOTSTRAP_M4_REQUIRE_NATIVE_EMIT:-1}"', $script);
        $this->assertStringContainsString('BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT="${BOOTSTRAP_M3_REQUIRE_NATIVE_EMIT:-1}"', $script);
    }

    public function testNorthStar5FastRunsBootstrapTrustPreflight(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/north-star5-verify.sh');
        $this->assertStringContainsString('bootstrap-trust-preflight.sh', $script);
        $this->assertStringContainsString('ns5_gen0_trust_preflight', $script);
        $this->assertStringContainsString('step 3t: gen-0 trust preflight', $script);
        $this->assertStringContainsString('#36145', $script);
    }
}
