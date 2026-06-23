<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\CheckdateRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9242: JIT checkdate routes through CheckdateJitHelper + VmDate PHP.
 *
 * @group aot-lint
 */
final class CheckdateRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesCheckdateForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        CheckdateRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__compiler_checkdate');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
    }

    public function testCheckdateRuntimeRoutesThroughCheckdateJitHelper(): void
    {
        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/CheckdateRuntime.php');
        $this->assertStringContainsString('VmCheckdate', $runtimeSource);
        $this->assertStringNotContainsString('MONTH_DAYS', $runtimeSource);

        $helperSource = (string) \file_get_contents(__DIR__.'/../../../ext/standard/CheckdateJitHelper.php');
        $this->assertStringContainsString('VmCheckdate::validate', $helperSource);
    }
}
