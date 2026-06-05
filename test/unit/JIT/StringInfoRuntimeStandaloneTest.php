<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringInfo;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6124: AOT standalone must define info helpers without phpc_info.c.
 *
 * @group aot-lint
 */
final class StringInfoRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesInfoForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringInfo::ensureLinked($ctx);

        foreach ([
            '__compiler_phpversion',
            '__compiler_php_sapi_name',
            '__compiler_php_uname',
            '__compiler_extension_loaded',
            '__compiler_get_loaded_extensions',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
