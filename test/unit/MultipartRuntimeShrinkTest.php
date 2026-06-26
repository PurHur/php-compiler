<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\HashTable;
use PHPCompiler\Web\MultipartParserJitHelper;
use PHPUnit\Framework\TestCase;

/** StringMultipart JIT routes through MultipartParser PHP SSOT, LLVM quarantined (#9394). */
final class MultipartRuntimeShrinkTest extends TestCase
{
    private const BASELINE_LOC = 1359;

    public function testStringMultipartIsThinRouter(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMultipart.php');
        $this->assertStringContainsString('StringMultipartStandaloneLlvm', $source);
        $this->assertStringContainsString('shouldLinkStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitParseMultipartPost', $source);
        $this->assertLessThan(80, substr_count($source, "\n") + 1);
    }

    public function testStandaloneLlvmQuarantined(): void
    {
        $loc = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMultipartStandaloneLlvm.php'),
            "\n"
        ) + 1;
        $this->assertGreaterThan((int) floor(self::BASELINE_LOC * 0.8), $loc);
    }

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
}
