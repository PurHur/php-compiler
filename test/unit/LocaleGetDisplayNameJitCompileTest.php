<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\locale_get_display_name;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for locale_get_display_name() JIT lowering (#32120).
 *
 * IR via jitCompileBlock — no MCJIT execute (host module-verify is unrelated).
 *
 * @group llvm
 */
final class LocaleGetDisplayNameJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — locale_get_display_name JIT compile test needs LLVM (#32120)');
        }
    }

    public function testLiteralDisplayNameFoldsWithoutJitRefuse(): void
    {
        $code = <<<'PHP'
<?php
echo locale_get_display_name('de_DE', 'en'), "\n";
PHP;

        $runtime = new Runtime();
        BuiltinClasses::registerLocale($runtime->vmContext);
        $fn = new locale_get_display_name();
        $runtime->vmContext->declareFunction($fn);
        $context = $runtime->loadJitContext();
        $context->functionProxies['locale_get_display_name'] = $fn;

        $block = $runtime->parseAndCompile($code, 'locale_get_display_name_jit_fold_32120.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $bc = $context->module->printToString();
        $this->assertStringNotContainsString('JIT lowering not implemented', $bc);
        $this->assertStringNotContainsString('not implemented for JIT', $bc);
        $this->addToAssertionCount(1);
    }
}
