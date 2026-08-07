<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\TransliteratorTransliterateJitHelper;
use PHPUnit\Framework\TestCase;

/** Transliterator::transliterate JIT CT fold + NestedJIT fallback (#28657). */
final class TransliteratorTransliterateJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/transliterator_transliterate.php');
        $this->assertStringContainsString('JitTransliteratorTransliterate::invokeProcedural', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $create = (string) file_get_contents(__DIR__.'/../../ext/intl/transliterator_create.php');
        $this->assertStringContainsString('JitTransliteratorCreate::invoke', $create);
        $this->assertStringNotContainsString('not implemented for JIT', $create);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/VmTransliterator.php');
        $this->assertStringContainsString('JitTransliteratorTransliterate::invokeMethod', $method);
        $this->assertStringContainsString('JitTransliteratorCreate::invoke', $method);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitTransliteratorTransliterate.php');
        $this->assertStringContainsString('tryFoldIdSubject', $lowering);
        $this->assertStringContainsString('TransliteratorTransliterateRuntime::invokeCafe', $lowering);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['transliterator::transliterate']", $ctx);
        $this->assertStringContainsString("functionProxies['transliterator::create']", $ctx);
    }

    public function testHostHelperLatinAsciiCafe(): void
    {
        $this->assertSame('cafe', TransliteratorTransliterateJitHelper::latinAscii('café'));
        $this->assertSame('cafe', TransliteratorTransliterateJitHelper::cafeArgv('x'));
    }
}
