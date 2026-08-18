<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\locale_filter_matches;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for locale_filter_matches() JIT lowering (#32119).
 *
 * IR via jitCompileBlock — no MCJIT execute (host module-verify is unrelated).
 *
 * @group llvm
 */
final class LocaleFilterMatchesJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — locale_filter_matches JIT compile test needs LLVM (#32119)');
        }
    }

    public function testLiteralFilterMatchesFoldsWithoutJitRefuse(): void
    {
        $code = <<<'PHP'
<?php
echo locale_filter_matches('de-DE', 'de', false) ? 'true' : 'false', "\n";
PHP;

        $runtime = new Runtime();
        BuiltinClasses::registerLocale($runtime->vmContext);
        $fn = new locale_filter_matches();
        $runtime->vmContext->declareFunction($fn);
        $context = $runtime->loadJitContext();
        $context->functionProxies['locale_filter_matches'] = $fn;

        $block = $runtime->parseAndCompile($code, 'locale_filter_matches_jit_fold_32119.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $bc = $context->module->printToString();
        $this->assertStringNotContainsString('JIT lowering not implemented', $bc);
        $this->assertStringNotContainsString('not implemented for JIT', $bc);
        $this->addToAssertionCount(1);
    }
}
