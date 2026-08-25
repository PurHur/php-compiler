<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on HtmlEntities/Decode/ErrorHandler/ExceptionHandler (#34612 / peer #34605).
 *
 * Thin AOT hello-world must not NestedJIT those ABIs; call sites ensureLinked lazily
 * (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyHtmlErrorHandlerRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerHtmlErrorHandler(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34612', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        foreach ([
            'HtmlEntitiesJit::ensureStandaloneBodies($this)',
            'StringHtmlspecialcharsDecode::ensureStandaloneBodies($this)',
            'ErrorHandlerJitRuntime::ensureStandaloneBodies($this)',
            'ExceptionHandlerJitRuntime::ensureStandaloneBodies($this)',
        ] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $minimalBody,
                "ensureMinimalUserStandaloneBodies must not eagerly {$needle} (#34612)"
            );
        }

        // Essentials for thin argv / getenv surface stay (#34695 dropped ObOutput;
        // #34641 dropped StringTriggerError).
        // LastError dropped in #34631 (peer this test).
        foreach ([
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            'EnvLocalRuntime::ensureLinked($this)',
            'SuperglobalNameRuntime::ensureLinked($this)',
        ] as $keep) {
            $this->assertStringContainsString($keep, $minimalBody, "keep {$keep} in minimal (#34612)");
        }
        $this->assertStringNotContainsString(
            'ObOutputRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimal must not eagerly ObOutputRuntime (#34695)'
        );
        $this->assertStringNotContainsString(
            'StringTriggerError::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly StringTriggerError (#34641)'
        );
        $this->assertStringNotContainsString(
            'LastErrorRuntime::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly LastErrorRuntime (#34631)'
        );
    }

    public function testCallSitesEnsureBeforeLookup(): void
    {
        $checks = [
            'ext/standard/htmlentities.php' => 'HtmlEntitiesJit::ensureLinked',
            'ext/standard/JitHtmlspecialcharsDecode.php' => 'StringHtmlspecialcharsDecode::ensureLinked',
            'ext/standard/JitErrorHandler.php' => 'ErrorHandlerJitRuntime::ensureLinked',
            'ext/standard/JitTriggerErrorKernel.php' => 'ErrorHandlerJitRuntime::ensureLinked',
            'ext/standard/JitExceptionHandler.php' => 'ExceptionHandlerJitRuntime::ensureLinked',
            'lib/JIT/TryCatchHelper.php' => 'ExceptionHandlerJitRuntime::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#34612)');
        }

        // Mid-{main} ensureLinked must restore builder insert (#34612 / Module.php:180).
        foreach ([
            'lib/JIT/Builtin/HtmlEntitiesJit.php' => 'BasicBlockHelper::restoreInsertBlock',
            'lib/JIT/Builtin/StringHtmlspecialcharsDecode.php' => 'BasicBlockHelper::restoreInsertBlock',
            'lib/JIT/Builtin/ErrorHandlerJitRuntime.php' => 'restoreInsertBlock($context, $restoreBlock)',
            'lib/JIT/Builtin/ExceptionHandlerJitRuntime.php' => 'restoreInsertBlock($context, $restoreBlock)',
        ] as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must restore insert (#34612)');
        }
    }

    public function testNoNewRuntimeCForMinimalHtmlErrorHandlerLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'html_entities.c',
            'htmlspecialchars_decode.c',
            'error_handler.c',
            'exception_handler.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                "must not add {$name} for #34612 — PHP JIT bridges only"
            );
        }
    }
}
