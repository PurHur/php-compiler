<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ObGzhandlerJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** ObGzhandlerJitRuntime must route through ObGzhandlerJitHelper PHP, not LLVM handler body (#9091, #9798). */
final class ObGzhandlerJitRuntimeShrinkTest extends TestCase
{
    public function testObGzhandlerJitRuntimeUsesObGzhandlerJitHelperNotLlvmHandleBody(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObGzhandlerJitRuntime.php');
        $this->assertStringContainsString('ObGzhandlerJitHelper', $source);
        $this->assertStringContainsString('ObGzhandlerStandaloneLlvm', $source);
        $this->assertStringContainsString('readAcceptEncodingFromServer', $source);
        $this->assertStringNotContainsString('emitReadAcceptEncodingString', $source);
        $this->assertStringNotContainsString('emitHandleBody', $source);
        $this->assertStringNotContainsString('emitPassthroughBody', $source);
        $this->assertStringNotContainsString('lookupFunction(\'strstr\')', $source);
        $this->assertStringNotContainsString('GLOBAL_ENCODING', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testObGzhandlerStandaloneLlvmQuarantinesAcceptEncodingWalk(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObGzhandlerStandaloneLlvm.php');
        $this->assertStringContainsString('emitReadAcceptEncodingString', $source);
        $this->assertStringContainsString('HTTP_ACCEPT_ENCODING', $source);
        $this->assertStringNotContainsString('ZlibEncodeJitHelper::gzencode', $source);
    }

    public function testObGzhandlerJitHelperIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ObGzhandlerJitHelper.php');
        $this->assertStringContainsString('ZlibEncodeJitHelper::gzencode', $source);
        $this->assertStringContainsString('resolveEncodingFromAcceptHeader', $source);
        $this->assertStringContainsString('readAcceptEncodingFromServer', $source);
        $this->assertStringNotContainsString('VmObGzhandler::', $source);
        $this->assertStringNotContainsString('$_SERVER', $source);
        $this->assertStringNotContainsString('ResponseContext', $source);
    }

    public function testObGzhandlerJitHelperLifecycle(): void
    {
        $encoding = ObGzhandlerJitHelper::resolveEncodingFromAcceptHeader('gzip');
        $this->assertSame(\ZLIB_ENCODING_GZIP, $encoding);
        $this->assertSame('', ObGzhandlerJitHelper::handle('', \PHP_OUTPUT_HANDLER_START, $encoding));
        $out = ObGzhandlerJitHelper::handle('hello world', \PHP_OUTPUT_HANDLER_END, $encoding);
        $this->assertNotSame('hello world', $out);
        $this->assertSame("\x1f\x8b", \substr($out, 0, 2));

        $flushed = ObGzhandlerJitHelper::flushBuffer('hello world', $encoding);
        $this->assertSame("\x1f\x8b", \substr($flushed, 0, 2));
        $this->assertSame(0, ObGzhandlerJitHelper::resolveEncodingFromAcceptHeader(''));
    }

    public function testObGzhandlerJitHelperReadAcceptEncodingFromServer(): void
    {
        $ht = new HashTable();
        $value = new Variable();
        $value->string('gzip, deflate');
        $ht->add('HTTP_ACCEPT_ENCODING', $value);
        $this->assertSame('gzip, deflate', ObGzhandlerJitHelper::readAcceptEncodingFromServer($ht));
        $this->assertSame('', ObGzhandlerJitHelper::readAcceptEncodingFromServer(null));
    }
}
