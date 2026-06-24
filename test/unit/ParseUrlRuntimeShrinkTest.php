<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ParseUrlJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** parse_url JIT routes through ParseUrlJitHelper PHP not ParseUrlJit LLVM (#9358). */
final class ParseUrlRuntimeShrinkTest extends TestCase
{
    public function testParseUrlJitFileDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ParseUrlJit.php');
    }

    public function testParseUrlRoutesThroughRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ParseUrl.php');
        $this->assertStringContainsString('ParseUrlRuntime', $source);
        $this->assertStringNotContainsString('ParseUrlJit::', $source);
    }

    public function testParseUrlRuntimeUsesJitHelperNotLlvmParser(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ParseUrlRuntime.php');
        $this->assertStringContainsString('ParseUrlJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('emitParseParts', $source);
        $this->assertStringNotContainsString('__phpc_parse_url_strdup0', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testParseUrlJitHelperMatchesVmString(): void
    {
        ParseUrlJitHelper::resetForTest();
        $url = 'http://u:p@host:8080/path?q=1#frag';

        $tag = ParseUrlJitHelper::parseUrlComponent($url, \PHP_URL_USER);
        $this->assertSame(2, $tag);
        $this->assertSame(VmString::parseUrl($url, \PHP_URL_USER), ParseUrlJitHelper::lastString());

        ParseUrlJitHelper::resetForTest();
        $tag = ParseUrlJitHelper::parseUrlComponent($url, \PHP_URL_PORT);
        $this->assertSame(3, $tag);
        $this->assertSame(VmString::parseUrl($url, \PHP_URL_PORT), ParseUrlJitHelper::lastInt());

        $assoc = ParseUrlJitHelper::parseUrlAssoc($url);
        $expected = VmString::parseUrl($url, -1);
        $this->assertIsArray($expected);
        $this->assertSame($expected, $assoc);
    }
}
