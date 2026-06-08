<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringFsDir;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6342: upload temp LLVM helpers must lower without phpc_upload_temp.c.
 *
 * @group aot-lint
 */
final class UploadTempRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesUploadTempHelpersForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        StringFsDir::ensureLinked($ctx);

        foreach ([
            '__phpc_upload_path_has_traversal',
            '__phpc_upload_tmpdir_name',
            '__phpc_upload_is_valid_temp',
            '__compiler_is_uploaded_file',
            '__compiler_move_uploaded_file',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name);
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name);
        }
    }
}
