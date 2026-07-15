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

    public function testVmStreamMetaDoesNotReferenceHostStreamSetBlocking(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamMeta.php');
        $this->assertStringNotContainsString('\\stream_set_blocking(', $source);
    }

    public function testBuildMetaArrayForPhpTempStream(): void
    {
        $handle = VmFs::fopen('php://temp', 'r+');
        $this->assertNotFalse($handle);
        $meta = VmFs::streamGetMetaData($handle);
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $meta);
        $streamType = null;
        foreach ($meta->iterateKeyed(false) as [$keyVar, $value]) {
            if (Variable::TYPE_STRING === $keyVar->type && 'stream_type' === $keyVar->toString()) {
                $streamType = $value->toString();
            }
        }
        $this->assertSame('TEMP', $streamType);
        VmFs::fclose($handle);
    }

    public function testBuildMetaArrayForPhpMemoryStream(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        $meta = VmFs::streamGetMetaData($handle);
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $meta);
        $streamType = null;
        foreach ($meta->iterateKeyed(false) as [$keyVar, $value]) {
            if (Variable::TYPE_STRING === $keyVar->type && 'stream_type' === $keyVar->toString()) {
                $streamType = $value->toString();
            }
        }
        $this->assertSame('MEMORY', $streamType);
        VmFs::fclose($handle);
    }

    public function testWrapperTypeForDataUri(): void
    {
        $this->assertSame('RFC2397', VmStreamMeta::wrapperTypeForUri('data://text/plain,hello'));
    }

    public function testWrapperTypeForPhpFilterUri(): void
    {
        $this->assertSame('PHP', VmStreamMeta::wrapperTypeForUri('php://filter/read=string.rot13/resource=data://text/plain,hello'));
    }

    public function testStreamGetMetaDataForDataUri(): void
    {
        $handle = VmFs::fopen('data://text/plain,hello', 'r');
        $this->assertNotFalse($handle);
        $meta = VmFs::streamGetMetaData($handle);
        $wrapperType = null;
        foreach ($meta->iterateKeyed(false) as [$keyVar, $value]) {
            if (Variable::TYPE_STRING === $keyVar->type && 'wrapper_type' === $keyVar->toString()) {
                $wrapperType = $value->toString();
            }
        }
        $this->assertSame('RFC2397', $wrapperType);
        VmFs::fclose($handle);
    }

    public function testStreamGetMetaDataForPhpFilterUri(): void
    {
        $filterUri = 'php://filter/read=string.rot13/resource=data://text/plain,hello';
        $handle = VmFs::fopen($filterUri, 'r');
        $this->assertNotFalse($handle);
        $meta = VmFs::streamGetMetaData($handle);
        $wrapperType = null;
        $uri = null;
        foreach ($meta->iterateKeyed(false) as [$keyVar, $value]) {
            if (Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $key = $keyVar->toString();
            if ('wrapper_type' === $key) {
                $wrapperType = $value->toString();
            } elseif ('uri' === $key) {
                $uri = $value->toString();
            }
        }
        $this->assertSame('PHP', $wrapperType);
        $this->assertSame($filterUri, $uri);
        VmFs::fclose($handle);
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

    public function testStdioStreamTypeInheritsNonStdioFromHostResource(): void
    {
        if (!\function_exists('stream_socket_pair')) {
            $this->markTestSkipped('stream_socket_pair unavailable');
        }
        $pair = @\stream_socket_pair(\AF_UNIX, \SOCK_STREAM, \STREAM_IPPROTO_IP);
        if (false === $pair) {
            $this->markTestSkipped('stream_socket_pair failed');
        }
        try {
            $hostType = VmStreamMeta::stdioInheritedStreamType('php://stdin', $pair[0]);
            $this->assertSame('unix_socket', $hostType);

            $meta = VmStreamMeta::buildMetaArray('php://stdin', $pair[0]);
            $this->assertSame('unix_socket', $meta['stream_type']);
            $this->assertSame('PHP', $meta['wrapper_type']);
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }
    }

    public function testStreamGetMetaDataForPhpStdinUsesHostInheritedType(): void
    {
        $handle = VmFs::fopen('php://stdin', 'r');
        $this->assertNotFalse($handle);
        $meta = VmFs::streamGetMetaData($handle);
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $meta);
        $streamType = null;
        foreach ($meta->iterateKeyed(false) as [$keyVar, $value]) {
            if (Variable::TYPE_STRING === $keyVar->type && 'stream_type' === $keyVar->toString()) {
                $streamType = $value->toString();
            }
        }
        $hostFp = @\fopen('php://stdin', 'rb');
        $expected = 'STDIO';
        if (\is_resource($hostFp)) {
            $hostMeta = @\stream_get_meta_data($hostFp);
            if (\is_array($hostMeta) && isset($hostMeta['stream_type']) && \is_string($hostMeta['stream_type'])) {
                $expected = 'STDIO' === $hostMeta['stream_type']
                    ? 'STDIO'
                    : VmStreamMeta::stdioInheritedStreamType('php://stdin', $hostFp) ?? $hostMeta['stream_type'];
            }
            fclose($hostFp);
        }
        $this->assertSame($expected, $streamType);
        VmFs::fclose($handle);
    }

    public function testStreamGetMetaDataViaVmFsHandle(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        $meta = VmFs::streamGetMetaData($handle);
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $meta);
        $found = [
            'uri' => false,
            'unread_bytes' => false,
            'seekable' => false,
        ];
        foreach ($meta->iterateKeyed(false) as [$keyVar, $value]) {
            if (Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $key = $keyVar->toString();
            if ('uri' === $key) {
                $this->assertSame(Variable::TYPE_STRING, $value->type);
                $this->assertSame('php://memory', $value->toString());
                $found['uri'] = true;
            } elseif ('unread_bytes' === $key) {
                $this->assertSame(Variable::TYPE_INTEGER, $value->type);
                $this->assertSame(0, $value->toInt());
                $found['unread_bytes'] = true;
            } elseif ('seekable' === $key) {
                $this->assertSame(Variable::TYPE_BOOLEAN, $value->type);
                $this->assertTrue($value->toBool());
                $found['seekable'] = true;
            }
        }
        $this->assertTrue($found['uri']);
        $this->assertTrue($found['unread_bytes']);
        $this->assertTrue($found['seekable']);
        VmFs::fclose($handle);
    }

    public function testStreamSetBlockingOnMemoryStream(): void
    {
        $handle = VmFs::tmpfile();
        $this->assertNotFalse($handle);
        $this->assertTrue(VmFs::streamSetBlocking($handle, true));
        VmFs::fclose($handle);
    }

    public function testStreamSetBlockingUpdatesBlockedMetadata(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        $this->assertTrue(VmFs::streamSetBlocking($handle, false));
        $meta = VmFs::streamGetMetaData($handle);
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $meta);
        $blocked = null;
        foreach ($meta->iterateKeyed(false) as [$keyVar, $value]) {
            if (Variable::TYPE_STRING === $keyVar->type && 'blocked' === $keyVar->toString()) {
                $blocked = $value->toBool();
            }
        }
        $this->assertFalse($blocked);
        VmFs::fclose($handle);
    }
}
