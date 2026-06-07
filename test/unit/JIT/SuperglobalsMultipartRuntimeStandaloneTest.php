<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringMultipart;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #7302: AOT standalone must define multipart POST helper without C bodies
 * in superglobals_refresh.c (PHP LLVM via StringMultipartJit).
 *
 * @group aot-lint
 */
final class SuperglobalsMultipartRuntimeStandaloneTest extends TestCase
{
    public function testEnsureStandaloneDefinesMultipartPostHelper(): void
    {
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
    }

    public function testSuperglobalsRefreshCDoesNotDefineMultipartParser(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../lib/AOT/runtime/superglobals_refresh.c');
        $this->assertStringNotContainsString('sg_extract_boundary', $source);
        $this->assertStringNotContainsString('sg_find_header_value', $source);
        $this->assertStringNotContainsString('sg_multipart_param', $source);
        $this->assertStringNotContainsString('sg_set_file_entry', $source);
        $this->assertStringNotContainsString('sg_normalize_body_newlines', $source);
        $this->assertDoesNotMatchRegularExpression('/\bparse_multipart_post\b/', $source);
        $this->assertStringContainsString('__phpc_parse_multipart_post', $source);
    }
}
