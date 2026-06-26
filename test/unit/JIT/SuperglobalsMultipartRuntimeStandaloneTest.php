<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringMultipart;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #7302 / #9394: AOT standalone LLVM refresh must define multipart POST helpers
 * when PHP_COMPILER_SUPERGLOBAL_REFRESH_LLVM=1 (quarantined in StringMultipartStandaloneLlvm).
 *
 * @group aot-lint
 */
final class SuperglobalsMultipartRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesMultipartPostHelper(): void
    {
        $prev = getenv('PHP_COMPILER_SUPERGLOBAL_REFRESH_LLVM');
        putenv('PHP_COMPILER_SUPERGLOBAL_REFRESH_LLVM=1');
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
            StringMultipart::ensureStandaloneBodies($ctx);

        foreach (
            [
                '__phpc_parse_multipart_post',
                '__phpc_multipart_extract_boundary',
                '__phpc_multipart_set_file_entry',
                '__phpc_multipart_normalize_body_newlines',
            ] as $name
        ) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn, $name.' must be linked for standalone AOT');
            $this->assertGreaterThan(0, $fn->countBasicBlocks(), $name.' must have LLVM body');
        }
        } finally {
            if (false === $prev || '' === $prev) {
                putenv('PHP_COMPILER_SUPERGLOBAL_REFRESH_LLVM');
            } else {
                putenv('PHP_COMPILER_SUPERGLOBAL_REFRESH_LLVM='.$prev);
            }
        }
    }

    public function testSuperglobalsRefreshCFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
    }

    public function testDefaultStandaloneSkipsMultipartLlvmUnlessOptIn(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringMultipart.php');
        $this->assertStringContainsString('PHP_COMPILER_SUPERGLOBAL_REFRESH_LLVM', $source);
        $this->assertStringContainsString('shouldLinkStandaloneLlvm', $source);
        $this->assertStringContainsString('return false', $source);
    }
}
