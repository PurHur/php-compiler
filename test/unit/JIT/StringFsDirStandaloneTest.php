<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringFsDir;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6982: fs-dir runtime symbols must LLVM-lower in standalone AOT mode.
 *
 * @group aot-lint
 */
final class StringFsDirStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesFsDirHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringFsDir::ensureLinked($ctx);

        foreach ([
            '__compiler_copy',
            '__compiler_touch',
            '__compiler_mkdir',
            '__phpc_stat',
            '__compiler_sys_get_temp_dir',
            '__compiler_tempnam',
            '__compiler_chgrp',
            '__compiler_chown',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
