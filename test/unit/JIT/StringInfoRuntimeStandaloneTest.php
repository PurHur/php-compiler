<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringInfo;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5492 / #6124: AOT standalone must define info helpers without phpc_info.c.
 *
 * @group aot-lint
 */
final class StringInfoRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesInfoC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_info.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_info.c', $linker);
        $info = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringInfo.php');
        $this->assertStringContainsString('__compiler_phpversion', $info);
        $this->assertStringContainsString('InfoJitHelper', $info);
        $this->assertStringContainsString('VmInfo', $info);
    }

    public function testEnsureLinkedDefinesInfoForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringInfo::ensureLinked($ctx);

        foreach ([
            '__compiler_phpversion',
            '__compiler_php_sapi_name',
            '__compiler_zend_version',
            '__compiler_php_uname',
            '__compiler_extension_loaded',
            '__compiler_get_loaded_extensions',
            '__compiler_get_extension_funcs',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
