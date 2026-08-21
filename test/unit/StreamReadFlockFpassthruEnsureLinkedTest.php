<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * After Type always-on flock/fpassthru drops (#33104 / #33106), user-script JIT
 * must ensureLinked StreamRead before lookup (#33113) — peer JitFgetc.
 */
final class StreamReadFlockFpassthruEnsureLinkedTest extends TestCase
{
    public function testJitFlockEnsureLinksStreamReadBeforeLookup(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFlock.php');
        $this->assertStringContainsString('#33113', $src);
        $this->assertStringContainsString('StreamReadRuntime::ensureLinked', $src);
        $this->assertMatchesRegularExpression(
            '/ensureLinked\(\$context\);.*lookupFunction\(\s*[\'"]__compiler_flock[\'"]/s',
            $src,
            'JitFlock must ensureLinked before __compiler_flock lookup (#33113)'
        );
    }

    public function testJitFpassthruEnsureLinksStreamReadBeforeLookup(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFpassthru.php');
        $this->assertStringContainsString('#33113', $src);
        $this->assertStringContainsString('StreamReadRuntime::ensureLinked', $src);
        $this->assertMatchesRegularExpression(
            '/ensureLinked\(\$context\);.*lookupFunction\(\s*[\'"]__compiler_fpassthru[\'"]/s',
            $src,
            'JitFpassthru must ensureLinked before __compiler_fpassthru lookup (#33113)'
        );
    }

    public function testNoNewRuntimeC(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/flock.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/flock.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/fpassthru.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/fpassthru.c');
    }
}
