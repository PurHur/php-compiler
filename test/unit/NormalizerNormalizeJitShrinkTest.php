<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Normalizer::normalize JIT routes through NestedJIT helper (#28654). */
final class NormalizerNormalizeJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/normalizer_normalize.php');
        $this->assertStringContainsString('JitNormalizerNormalize::invokeProcedural', $builtin);
        $this->assertStringNotContainsString('JIT runtime lowering is deferred', $builtin);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/NormalizerNormalize.php');
        $this->assertStringContainsString('JitNormalizerNormalize::invokeMethod', $method);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitNormalizerNormalize.php');
        $this->assertStringContainsString('NormalizerNormalizeRuntime::invoke', $lowering);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/intl/NormalizerNormalizeJitHelper.php');
        $this->assertStringContainsString('normalizeArgv', $helper);
        $this->assertStringContainsString('UnicodeCanonical::normalizeNfc', $helper);
        $this->assertStringNotContainsString('VmNormalizer::normalize', $helper);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/NormalizerNormalizeRuntime.php');
        $this->assertStringContainsString('NormalizerNormalizeJitHelper', $runtime);
        $this->assertStringContainsString('phpc_normalizer_normalize', $runtime);
        $this->assertStringContainsString('UnicodeCanonical.php', $runtime);
        $this->assertStringContainsString('ensureCompiledBundle', $runtime);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['normalizer::normalize']", $ctx);
    }

    public function testJitHelperNfcCombiningAcute(): void
    {
        $s = "e\u{0301}";
        $this->assertSame(
            "é",
            \PHPCompiler\ext\intl\NormalizerNormalizeJitHelper::normalizeArgv(
                $s,
                \PHPCompiler\ext\intl\VmNormalizer::FORM_C
            )
        );
        $this->assertSame(
            'c3a9',
            bin2hex(\PHPCompiler\ext\intl\NormalizerNormalizeJitHelper::normalizeArgv(
                $s,
                \PHPCompiler\ext\intl\VmNormalizer::FORM_C
            ))
        );
    }

    public function testSpineBundleIncludesNormalizerNormalizeHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('NormalizerNormalizeJitHelper.php', $spine);
        $this->assertStringContainsString('JitNormalizerNormalize.php', $spine);
        $this->assertStringContainsString('NormalizerNormalizeRuntime.php', $spine);
        $this->assertStringContainsString('Call/NormalizerNormalize.php', $spine);
    }
}
