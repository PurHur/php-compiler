<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on TokenGetAll/Highlight/Hebrev/Hebrevc
 * no-ops + SuperglobalNameRuntime NestedJIT (#35035 / peer #34812).
 *
 * Full standalone must not NestedJIT is_superglobal_name during init (#32122 .1 mint class).
 * TokenGetAll/Highlight/Hebrev/Hebrevc ensureStandaloneBodies are no-ops — helpers compile
 * on first lowering.
 */
final class ContextFullStandaloneLazyTokenHebrevSuperglobalShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerTokenHighlightHebrevAndSuperglobalName(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35035', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'TokenGetAll::ensureStandaloneBodies($this)',
            'Highlight::ensureStandaloneBodies($this)',
            'Hebrev::ensureStandaloneBodies($this)',
            'Hebrevc::ensureStandaloneBodies($this)',
            'SuperglobalNameRuntime::ensureLinked($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35035)'
            );
        }

        // Still links refresh used by inventory / standalone main.
        // StringStrspn left ensureFull in #35089 — do not re-assert eager strspn here.
        $this->assertStringNotContainsString(
            'SuperglobalRefreshRuntime::ensureStandaloneBodies($this)',
            $fullBody,
            'SuperglobalRefresh deferred to compileToFile (#35137)'
        );
    }

    public function testCallSitesStillEnsureBeforeLookup(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSuperglobalName.php');
        $this->assertStringContainsString('StringSuperglobalName::ensureLinked($context)', $jit);

        $hebrev = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHebrev.php');
        $this->assertStringContainsString('Hebrev::ensureLinked($context)', $hebrev);

        $hebrevc = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHebrevc.php');
        $this->assertStringContainsString('HebrevcBuiltin::ensureLinked($context)', $hebrevc);

        $highlight = (string) file_get_contents(__DIR__.'/../../ext/standard/JitHighlight.php');
        $this->assertStringContainsString('Highlight::ensureLinked($context)', $highlight);

        $token = (string) file_get_contents(__DIR__.'/../../ext/tokenizer/JitTokenGetAll.php');
        $this->assertStringContainsString('TokenGetAll::helperFunction($context)', $token);
    }

    public function testSuperglobalNameRuntimeDocumentsLazyFull(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SuperglobalNameRuntime.php');
        $this->assertStringContainsString('#35035', $source);
        $this->assertStringContainsString('ensureFullStandaloneBodies', $source);
    }

    public function testNoNewRuntimeCForFullStandaloneLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/token_get_all.c',
            'must not add token_get_all.c for #35035 — PHP JIT bridges only'
        );
        $this->assertFileDoesNotExist(
            $runtimeDir.'/is_superglobal_name.c',
            'must not re-add is_superglobal_name.c for #35035'
        );
    }
}
