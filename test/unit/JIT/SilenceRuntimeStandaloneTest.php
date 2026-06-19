<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SilenceRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #9197: AOT standalone @ silence must use ErrorSilenceJitHelper PHP, not LLVM globals.
 *
 * @group aot-lint
 */
final class SilenceRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesSilenceRuntimeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        SilenceRuntime::ensureLinked($ctx);

        foreach (
            [
                '__compiler_begin_silence',
                '__compiler_end_silence',
                '__compiler_phpc_error_level_enabled',
                '__compiler_error_reporting',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }

        $this->assertNull($ctx->module->getNamedGlobal('phpc_ini_silence_depth'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_ini_silence_saved_error_reporting'));
        $this->assertNull($ctx->module->getNamedGlobal('phpc_ini_error_reporting'));
    }
}
