<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\JitPregReplaceCallback;
use PHPUnit\Framework\TestCase;

/**
 * Nyholm Uri IncludeHelper sites must take UriRawurlencodeReplaceJitHelper (#36382).
 */
final class UriRawurlencodeCallSite36382Test extends TestCase
{
    public function testNyholmCallSiteFallbackIsPrivateAndDocumented(): void
    {
        $ref = new \ReflectionClass(JitPregReplaceCallback::class);
        $this->assertTrue($ref->hasMethod('isNyholmUriRawurlencodeCallSite'));
        $m = $ref->getMethod('isNyholmUriRawurlencodeCallSite');
        $this->assertTrue($m->isPrivate());

        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitPregReplaceCallback.php');
        $this->assertStringContainsString('jitLoweringScopedName', $src);
        $this->assertStringContainsString('::withuserinfo', $src);
        $this->assertStringContainsString('::filterpath', $src);
        $this->assertStringContainsString('::filterqueryandfragment', $src);
    }

    public function testContextTracksLoweringScopedName(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertStringContainsString('public ?string $jitLoweringScopedName', $src);

        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('jitLoweringScopedName = $block->func->getScopedName()', $jit);
    }

    public function testUriHelperForcedUserScriptInlineOnly(): void
    {
        $ref = new \ReflectionClass(\PHPCompiler\AOT\HelperRuntimeCache::class);
        $const = $ref->getConstant('USER_SCRIPT_INLINE_ONLY_LOGICALS');
        $this->assertIsArray($const);
        $this->assertArrayHasKey(
            'phpcompiler\\ext\\standard\\urirawurlencodereplacejithelper::replaceargv',
            $const,
            'UriRawurlencodeReplaceJitHelper must NestedJIT into user AOT (#36382)'
        );
    }
}
