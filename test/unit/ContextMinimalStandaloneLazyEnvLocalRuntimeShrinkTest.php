<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on EnvLocalRuntime (#34807 / peer #34769).
 *
 * Thin AOT hello-world must not NestedJIT __compiler_env_local_* — getenv()/putenv()
 * lower via StringGetenv / PutenvJitHelper (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyEnvLocalRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerEnvLocalRuntime(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34807', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'EnvLocalRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly EnvLocalRuntime (#34807)'
        );

        // CLI argv still NestedJIT before {main} (#34812 dropped SuperglobalName).
        foreach ([
            'CliArgvRuntime::ensureStandaloneBodies($this)',
        ] as $keep) {
            $this->assertStringContainsString($keep, $minimalBody, "keep {$keep} in minimal (#34807)");
        }

        // bootstrap-aot still links env-local stubs without NestedJIT helper.
        $bootPos = strpos($context, 'private function ensureBootstrapAotStandaloneBodies');
        $this->assertNotFalse($bootPos);
        $bootEnd = strpos($context, 'private function ensureFullStandaloneBodies', $bootPos);
        $this->assertNotFalse($bootEnd);
        $bootBody = substr($context, $bootPos, $bootEnd - $bootPos);
        $this->assertStringContainsString(
            'EnvLocalRuntime::ensureBootstrapAotStubLinked($this)',
            $bootBody,
            'ensureBootstrapAotStandaloneBodies still ensureBootstrapAotStubLinked (#34807)'
        );
    }

    public function testEnvLocalRuntimeOrchestratorDocumentsLazyMinimal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EnvLocalRuntime.php');
        $this->assertStringContainsString('#34807', $source);
        $this->assertStringContainsString('JitEnvLocalKernel::ensureLinked', $source);
        $this->assertStringContainsString('ensureBootstrapAotStubLinked', $source);
    }

    public function testNoNewRuntimeCForMinimalEnvLocalLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/env_local.c',
            'must not add env_local.c for #34807 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/phpc_env_local.c',
            'must not re-add phpc_env_local.c for #34807'
        );
    }
}
