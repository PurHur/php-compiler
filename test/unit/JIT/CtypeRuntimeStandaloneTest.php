<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\CtypeRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9234: JIT ctype routes through CtypeJitHelper + VmCtype PHP.
 *
 * @group aot-lint
 */
final class CtypeRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesCtypeHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        CtypeRuntime::ensureLinked($ctx);

        foreach ([
            '__phpc_ctype_check_string',
            '__phpc_ctype_check_long',
            '__phpc_ctype_from_value',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }

    public function testCtypeRuntimeRoutesThroughCtypeJitHelper(): void
    {
        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/CtypeRuntime.php');
        $this->assertStringContainsString('CtypeJitHelper', $runtimeSource);
        $this->assertStringNotContainsString('emitIsDigit', $runtimeSource);

        $helperSource = (string) \file_get_contents(__DIR__.'/../../../ext/ctype/CtypeJitHelper.php');
        $this->assertStringContainsString('VmCtype::checkString', $helperSource);
        $this->assertStringContainsString('VmCtype::checkInt', $helperSource);
    }
}
