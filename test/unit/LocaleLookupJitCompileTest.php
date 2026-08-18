<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\locale_lookup;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for locale_lookup() JIT lowering (#32118).
 *
 * IR via jitCompileBlock — no MCJIT execute (host module-verify is unrelated).
 *
 * @group llvm
 */
final class LocaleLookupJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — locale_lookup JIT compile test needs LLVM (#32118)');
        }
    }

    public function testLiteralLookupFoldsWithoutJitRefuse(): void
    {
        $code = <<<'PHP'
<?php
echo locale_lookup(['de-DE', 'de'], 'de-CH', true, 'en'), "\n";
PHP;

        $runtime = new Runtime();
        BuiltinClasses::registerLocale($runtime->vmContext);
        $lookup = new locale_lookup();
        $runtime->vmContext->declareFunction($lookup);
        $context = $runtime->loadJitContext();
        $context->functionProxies['locale_lookup'] = $lookup;

        $block = $runtime->parseAndCompile($code, 'locale_lookup_jit_fold_32118.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $bc = $context->module->printToString();
        $this->assertStringNotContainsString('JIT lowering not implemented', $bc);
        $this->assertStringNotContainsString('not implemented for JIT', $bc);
        $this->addToAssertionCount(1);
    }
}
