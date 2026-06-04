<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\IniRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5736: AOT standalone must define ini helpers without phpc_ini_set.c.
 *
 * @group aot-lint
 */
final class IniRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesIniRuntimeForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        IniRuntime::ensureLinked($ctx);

        foreach (
            [
                '__compiler_phpc_error_level_enabled',
                '__compiler_ini_get',
                '__compiler_ini_set',
                '__compiler_error_reporting',
                '__compiler_begin_silence',
                '__compiler_end_silence',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }

        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_ini_error_reporting'));
        $this->assertNotNull($ctx->module->getNamedGlobal('phpc_ini_memory_limit'));
    }
}
