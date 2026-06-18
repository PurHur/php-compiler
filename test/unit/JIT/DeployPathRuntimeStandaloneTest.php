<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringDeployPath;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9309: deploy-path JIT bridge compiles DeployPathJitHelper, not getenv/snprintf LLVM.
 *
 * @group aot-lint
 */
final class DeployPathRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesDeployPathBridge(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringDeployPath::ensureStandaloneBodies($ctx);

        $fn = $ctx->lookupFunction('__compiler_phpc_deploy_path');
        $this->assertNotNull($fn, '__compiler_phpc_deploy_path must be linked for standalone AOT');
        $this->assertGreaterThan(0, $fn->countBasicBlocks(), '__compiler_phpc_deploy_path must have LLVM body');

        $lc = \strtolower('PHPCompiler\\ext\\standard\\DeployPathJitHelper::resolve');
        $this->assertArrayHasKey($lc, $ctx->functions, 'DeployPathJitHelper::resolve must be compiled into module');
    }
}
