<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ParseUrlJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * parse_url JIT routes through ParseUrlJitHelper PHP not ParseUrlJit LLVM (#9358).
 * NestedJIT via JitVmHelperLink::ensureCompiled (#22861 / peer #22575).
 */
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
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('emitParseParts', $source);
        $this->assertStringNotContainsString('__phpc_parse_url_strdup0', $source);
        $this->assertStringContainsString('ParseUrlAssocLlvm', $source);
        $this->assertLessThan(300, \substr_count($source, "\n") + 1);
        $assoc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ParseUrlAssocLlvm.php');
        $this->assertStringContainsString('setStringKeyString', $assoc);
        $this->assertStringContainsString('lastString', $assoc);
        $this->assertLessThan(220, \substr_count($assoc, "\n") + 1);
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

        // #22822 — invalid port / empty userinfo (php-src url.c)
        $this->assertFalse(VmString::parseUrl('http://ex.com:port/'));
        $this->assertNull(ParseUrlJitHelper::parseUrlAssoc('http://ex.com:99999/'));
        $emptyPass = VmString::parseUrl('http://user:@h/');
        $this->assertIsArray($emptyPass);
        $this->assertSame('', $emptyPass['pass']);
        $this->assertSame($emptyPass, ParseUrlJitHelper::parseUrlAssoc('http://user:@h/'));
        $emptyUser = VmString::parseUrl('http://:pass@h/');
        $this->assertIsArray($emptyUser);
        $this->assertSame('', $emptyUser['user']);
        $this->assertSame($emptyUser, ParseUrlJitHelper::parseUrlAssoc('http://:pass@h/'));
    }

    /** php-src url.c file:/// empty host is valid; http:/// is not (#32085). */
    public function testParseUrlFileTripleSlashEmptyHost(): void
    {
        $triple = VmString::parseUrl('file:///tmp/x');
        $this->assertSame(['scheme' => 'file', 'path' => '/tmp/x'], $triple);
        $this->assertSame($triple, ParseUrlJitHelper::parseUrlAssoc('file:///tmp/x'));

        $root = VmString::parseUrl('file:///');
        $this->assertSame(['scheme' => 'file', 'path' => '/'], $root);
        $this->assertSame($root, ParseUrlJitHelper::parseUrlAssoc('file:///'));

        $host = VmString::parseUrl('file://localhost/tmp/x');
        $this->assertSame(['scheme' => 'file', 'host' => 'localhost', 'path' => '/tmp/x'], $host);

        $this->assertFalse(VmString::parseUrl('file://'));
        $this->assertNull(ParseUrlJitHelper::parseUrlAssoc('file://'));
        $this->assertFalse(VmString::parseUrl('http:///tmp/x'));

        $drive = VmString::parseUrl('file:///c:/somedir/file.txt');
        $this->assertSame(['scheme' => 'file', 'path' => 'c:/somedir/file.txt'], $drive);
        $this->assertSame('/tmp/x', VmString::parseUrl('file:///tmp/x', \PHP_URL_PATH));
        $this->assertNull(VmString::parseUrl('file:///tmp/x', \PHP_URL_HOST));
    }
}
