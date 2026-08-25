<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on StringHtmlspecialchars (#34642 / peer #34612).
 *
 * Thin AOT hello-world must not eagerly NestedJIT htmlspecialchars ABI; htmlspecialchars.php
 * ensureLinked lazily (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyHtmlspecialcharsRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerHtmlspecialchars(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34642', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'StringHtmlspecialchars::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly StringHtmlspecialchars (#34642)'
        );

        // Essentials for thin argv / is_superglobal stay (#34807 dropped EnvLocal).
        foreach ([
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            'SuperglobalNameRuntime::ensureLinked($this)',
        ] as $keep) {
            $this->assertStringContainsString($keep, $minimalBody, "keep {$keep} in minimal (#34769)");
        }
        $this->assertStringNotContainsString(
            'ErrorBridge::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly ErrorBridge (#34769)'
        );
        $this->assertStringNotContainsString(
            'ExceptionBridge::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly ExceptionBridge (#34732)'
        );
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
    }

    public function testCallSiteEnsuresBeforeLookup(): void
    {
        $path = __DIR__.'/../../ext/standard/htmlspecialchars.php';
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);
        $this->assertStringContainsString(
            'StringHtmlspecialchars::ensureLinked',
            $source,
            'htmlspecialchars.php must ensure lazily (#34642)'
        );
    }

    public function testNoNewRuntimeCForMinimalHtmlspecialcharsLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/htmlspecialchars.c',
            'must not add htmlspecialchars.c for #34642 — PHP JIT bridges only'
        );
    }
}
