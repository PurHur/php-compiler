<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** finfo_file / finfo_buffer JIT routes through JitFinfo* (#27196, #28660). */
final class FinfoFileJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/fileinfo/finfo_file.php');
        $this->assertStringContainsString('JitFinfoFile::invokeProcedural', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bufferBuiltin = (string) file_get_contents(__DIR__.'/../../ext/fileinfo/finfo_buffer.php');
        $this->assertStringContainsString('JitFinfoBuffer::invokeProcedural', $bufferBuiltin);
        $this->assertStringNotContainsString('not implemented for JIT', $bufferBuiltin);

        $method = (string) file_get_contents(__DIR__.'/../../ext/fileinfo/BuiltinClasses.php');
        $this->assertStringContainsString('JitFinfoFile::invokeMethod', $method);
        $this->assertStringContainsString('JitFinfoBuffer::invokeMethod', $method);
        $this->assertStringContainsString('function call(JitContext $context', $method);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/fileinfo/JitFinfoFile.php');
        $this->assertStringContainsString('FinfoFileRuntime::invoke', $lowering);

        $bufferLowering = (string) file_get_contents(__DIR__.'/../../ext/fileinfo/JitFinfoBuffer.php');
        $this->assertStringContainsString('FinfoBufferRuntime::invoke', $bufferLowering);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FinfoFileRuntime.php');
        $this->assertStringContainsString('FinfoFileJitHelper', $runtime);
        $this->assertStringContainsString('phpc_finfo_file_mime', $runtime);
        $this->assertStringContainsString('StringBase64Decode::ensureLinked', $runtime);
        $this->assertStringContainsString('#34797', $runtime);

        $bufferRuntime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FinfoBufferRuntime.php');
        $this->assertStringContainsString('mimeFromBuffer', $bufferRuntime);
        $this->assertStringContainsString('phpc_finfo_buffer_mime', $bufferRuntime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/fileinfo/FinfoFileJitHelper.php');
        $this->assertStringContainsString('detectFromBytes', $helper);
        $this->assertStringContainsString('mimeFromBuffer', $helper);
        $this->assertStringContainsString('file_get_contents', $helper);
        $this->assertStringContainsString('decodeDataUri', $helper);
        $this->assertStringContainsString("'data:'", $helper);
        $this->assertStringContainsString('#34797', $helper);
        $this->assertStringContainsString('looksLikePngWithIhdr', $helper);

        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString(
            'finfofilejithelper::mimefrompath',
            $cache,
            'USER_SCRIPT_INLINE_ONLY must NestedJIT mimeFromPath — prelinked unit.o skips data:// (#34797)'
        );

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['finfo::file']", $ctx);
        $this->assertStringContainsString("functionProxies['finfo::buffer']", $ctx);
        $this->assertStringContainsString("functionProxies['finfo::__construct']", $ctx);
    }

    public function testJitHelperMimeMatchesPlainText(): void
    {
        $path = \sys_get_temp_dir().'/phpc_finfo_helper_27196.txt';
        \file_put_contents($path, 'hello');
        try {
            $this->assertSame('text/plain', \PHPCompiler\ext\fileinfo\FinfoFileJitHelper::mimeFromPath($path));
            $this->assertNull(\PHPCompiler\ext\fileinfo\FinfoFileJitHelper::mimeFromPath($path.'-missing'));
            $this->assertSame('text/plain', \PHPCompiler\ext\fileinfo\FinfoFileJitHelper::detectFromBytes('hello'));
            $this->assertSame('text/plain', \PHPCompiler\ext\fileinfo\FinfoFileJitHelper::mimeFromBuffer('hello'));
            $this->assertSame(
                'image/jpeg',
                \PHPCompiler\ext\fileinfo\FinfoFileJitHelper::detectFromBytes("\xff\xd8\xff\xe0")
            );
            $this->assertSame(
                'text/plain',
                \PHPCompiler\ext\fileinfo\FinfoFileJitHelper::mimeFromPath('data://text/plain,hello world')
            );
            $this->assertSame(
                'text/plain',
                \PHPCompiler\ext\fileinfo\FinfoFileJitHelper::mimeFromPath(
                    'data://text/plain;base64,'.\base64_encode('hello world')
                )
            );
            $png = \base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
            $this->assertNotFalse($png);
            $this->assertSame('image/png', \PHPCompiler\ext\fileinfo\FinfoFileJitHelper::detectFromBytes($png));
            // Host PHP decode is fine; NestedJIT base64_decode corrupts binary (AOT residual).
            $this->assertSame(
                'image/png',
                \PHPCompiler\ext\fileinfo\FinfoFileJitHelper::mimeFromPath('data://image/png;base64,'.\base64_encode($png))
            );
        } finally {
            @\unlink($path);
        }
    }
}
