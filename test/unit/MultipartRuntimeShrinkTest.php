<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\HashTable;
use PHPCompiler\Web\MultipartParserJitHelper;
use PHPUnit\Framework\TestCase;

/** Multipart POST uses MultipartParser PHP SSOT; deferred AOT kernel in ext/standard (#9394, #19454). */
final class MultipartRuntimeShrinkTest extends TestCase
{
    public function testMultipartParserJitHelperDelegatesToParser(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/Web/MultipartParserJitHelper.php');
        $this->assertStringContainsString('MultipartParser::populate', $source);

        $post = new HashTable();
        $files = new HashTable();
        $boundary = 'phpcTestB';
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"field\"\r\n\r\n"
            ."value\r\n"
            ."--{$boundary}--\r\n";
        MultipartParserJitHelper::populateTables(
            $post,
            $files,
            'multipart/form-data; boundary='.$boundary,
            $body
        );
        $this->assertSame('value', $post->find('field')?->toString());
        $this->assertSame(0, $files->getNumElements());
    }

    public function testStandaloneMultipartRoutesThroughMultipartRuntimeBridge(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringMultipartStandaloneLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/MultipartRuntimeUserScriptLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitMultipartKernel.php');
        $multipartRuntime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MultipartRuntime.php');
        $this->assertStringContainsString('MultipartNativeJitHelper', $multipartRuntime);
        $this->assertStringContainsString('JitMultipartKernel', $multipartRuntime);
        $this->assertStringNotContainsString('MultipartRuntimeUserScriptLlvm', $multipartRuntime);
        $refresh = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSuperglobalRefreshKernel.php');
        $this->assertStringContainsString('MultipartRuntime::ensureUserScriptLinked', $refresh);
        $this->assertStringContainsString('__compiler_multipart_populate_post_body', $refresh);
        $this->assertStringNotContainsString('StringMultipartStandaloneLlvm', $refresh);
        $this->assertStringNotContainsString('__phpc_parse_multipart_post', $refresh);
    }

    public function testSpineBundleIncludesMultipartKernelNotBuiltinUserScriptLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitMultipartKernel.php', $spine);
        $this->assertStringContainsString('MultipartRuntime.php', $spine);
        $this->assertStringNotContainsString('MultipartRuntimeUserScriptLlvm.php', $spine);
    }

    /** isset($_FILES['field']) must use offsetIsSet — upload slots are nested hashtables (#15624). */
    public function testIssetOnFilesSuperglobalUsesOffsetIsSetNotPeekStringKey(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/IssetHelperLlvm.php');
        $this->assertStringContainsString("'_FILES' !== \$container->superglobalName", $source);
    }

}
