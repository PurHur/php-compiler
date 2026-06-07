<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringFsGlob;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5459: glob/scandir vec helpers must LLVM-lower without phpc_fs_dir.c vec symbols.
 *
 * @group aot-lint
 */
final class StringFsGlobVecStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesGlobVecHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringFsGlob::ensureLinked($ctx);

        foreach ([
            '__phpc_strvec_free',
            '__phpc_glob_vec',
            '__phpc_scandir_vec',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
