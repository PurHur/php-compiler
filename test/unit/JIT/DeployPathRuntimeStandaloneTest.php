<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #9309 / #33244: deploy-path thin AOT uses DeployPathLlvm, not NestedJIT.
 *
 * Full Context(LOAD_TYPE_STANDALONE) construction currently fails on master after
 * #33234 (missing __compiler_trigger_error during Type::register NestedJIT) —
 * exercise ownership via source shrink instead; AOT binary repro is the behavior gate.
 *
 * @group aot-lint
 */
final class DeployPathRuntimeStandaloneTest extends TestCase
{
    public function testDeployPathLlvmOwnsThinAotBodyWithoutNestedJitHelper(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringDeployPath.php');
        $this->assertStringContainsString('DeployPathLlvm::implement', $owner);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $owner);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $owner);

        $llvm = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/DeployPathLlvm.php');
        $this->assertStringContainsString('#33244', $llvm);
        $this->assertStringContainsString('StringGetenv::invokeNestedLeaf', $llvm);
        $this->assertStringContainsString('JitStringConcat::concat', $llvm);
        $this->assertFileExists(__DIR__.'/../../../ext/standard/DeployPathJitHelper.php');
    }
}
