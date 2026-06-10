<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamMeta;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Issue #7908: VmFs stream meta must not delegate to host \\stream_get_meta_data(). */
final class VmStreamMetaRuntimeShrinkTest extends TestCase
{
    public function testVmFsDoesNotReferenceHostStreamGetMetaData(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmStreamMeta::', $source);
        $this->assertStringNotContainsString('\\stream_get_meta_data(', $source);
    }

    public function testBuildMetaArrayForPlainFilePath(): void
    {
        $fp = fopen('php://memory', 'r+');
        $this->assertIsResource($fp);
        try {
            $meta = VmStreamMeta::buildMetaArray('/tmp/example.txt', $fp);
            $this->assertSame('plainfile', $meta['wrapper_type']);
            $this->assertSame('/tmp/example.txt', $meta['uri']);
            $this->assertSame('STDIO', $meta['stream_type']);
            $this->assertIsBool($meta['eof']);
        } finally {
            fclose($fp);
        }
    }

    public function testStreamGetMetaDataViaVmFsHandle(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        $meta = VmFs::streamGetMetaData($handle);
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $meta);
        $foundUri = false;
        foreach ($meta->iterateKeyed(false) as [$keyVar, $value]) {
            if (Variable::TYPE_STRING === $keyVar->type && 'uri' === $keyVar->toString()) {
                $this->assertSame(Variable::TYPE_STRING, $value->type);
                $this->assertSame('php://memory', $value->toString());
                $foundUri = true;
            }
        }
        $this->assertTrue($foundUri);
        VmFs::fclose($handle);
    }

    public function testStreamSetBlockingOnMemoryStream(): void
    {
        $handle = VmFs::tmpfile();
        $this->assertNotFalse($handle);
        $this->assertTrue(VmFs::streamSetBlocking($handle, true));
        VmFs::fclose($handle);
    }
}
