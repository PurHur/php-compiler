<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\HashTable;
use PHPCompiler\Web\MultipartParserJitHelper;
use PHPUnit\Framework\TestCase;

/** Multipart POST uses MultipartParser PHP SSOT via SuperglobalRefreshRuntime (#9394, #13031). */
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
        $multipartRuntime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MultipartRuntime.php');
        $this->assertStringContainsString('MultipartNativeJitHelper', $multipartRuntime);
        $refresh = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalRefreshUserScriptLlvm.php');
        $this->assertStringContainsString('MultipartRuntime::ensureUserScriptLinked', $refresh);
        $this->assertStringContainsString('__compiler_multipart_populate_post_body', $refresh);
        $this->assertStringNotContainsString('StringMultipartStandaloneLlvm', $refresh);
        $this->assertStringNotContainsString('__phpc_parse_multipart_post', $refresh);
    }

    /** isset($_FILES['field']) must use offsetIsSet — upload slots are nested hashtables (#15624). */
    public function testIssetOnFilesSuperglobalUsesOffsetIsSetNotPeekStringKey(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/IssetHelperLlvm.php');
        $this->assertStringContainsString("'_FILES' !== \$container->superglobalName", $source);
    }

}
