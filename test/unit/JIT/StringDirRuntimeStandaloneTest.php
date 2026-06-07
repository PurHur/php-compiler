<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringDir;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5494: dir handle helpers must LLVM-lowering without phpc_dir.c.
 *
 * @group aot-lint
 */
final class StringDirRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesDirHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringDir::ensureLinked($ctx);

        foreach ([
            '__compiler_is_dir_resource',
            '__compiler_opendir',
            '__compiler_readdir',
            '__compiler_closedir',
            '__compiler_rewinddir',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
