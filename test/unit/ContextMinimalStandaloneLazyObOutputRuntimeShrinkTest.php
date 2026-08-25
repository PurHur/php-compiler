<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on ObOutputRuntime (#34695 / peer #34642).
 *
 * Thin AOT hello-world must not eagerly NestedJIT ob_* ABI; ValueEchoHelper /
 * ValueEchoRuntime ensureLinked lazily (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyObOutputRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerObOutput(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34695', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        $this->assertStringNotContainsString(
            'ObOutputRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimalUserStandaloneBodies must not eagerly ObOutputRuntime (#34695)'
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

        // ensureFull must not re-add ObOutput before ValueEcho (ValueEcho → ObOutput).
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'private function ', $fullPos + 1);
        $fullHead = false === $fullEnd
            ? substr($context, $fullPos, 2500)
            : substr($context, $fullPos, min(2500, $fullEnd - $fullPos));
        $this->assertStringContainsString('ValueEchoRuntime::ensureLinked($this)', $fullHead);
        $this->assertStringNotContainsString(
            'ObOutputRuntime::ensureLinked($this)',
            $fullHead,
            'ensureFull must not eagerly ObOutput before ValueEcho (#34695)'
        );
    }

    public function testValueEchoHelperEnsuresBeforeObLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ValueEchoHelper.php');
        $this->assertStringContainsString('#34695', $source);
        foreach (['echoLiteral', 'echoNativeLong', 'echoStringVariable'] as $method) {
            $pos = strpos($source, 'public static function '.$method);
            $this->assertNotFalse($pos, $method);
            $next = strpos($source, 'public static function ', $pos + 10);
            $body = false === $next
                ? substr($source, $pos)
                : substr($source, $pos, $next - $pos);
            $this->assertStringContainsString(
                'ObOutputRuntime::ensureLinked($context)',
                $body,
                $method.' must ensure ObOutput before __phpc_ob_echo_* (#34695)'
            );
        }
    }

    public function testJitEchoOpcodeEnsuresBeforeBareObLookup(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        // Concat/string echo uses bare __phpc_ob_echo_substr in the ECHO opcode switch (#34695).
        $this->assertMatchesRegularExpression(
            '/case Variable::TYPE_STRING:\s*\/\/ Lazy ob_\* — bare __phpc_ob_echo_\* lookups below \(#34695\)\.\s*JIT\\\\Builtin\\\\ObOutputRuntime::ensureLinked/s',
            $jit,
            'JIT ECHO TYPE_STRING must ensureLinked before bare __phpc_ob_echo_* (#34695)'
        );
        $this->assertMatchesRegularExpression(
            '/case Variable::TYPE_NATIVE_BOOL:\s*JIT\\\\Builtin\\\\ObOutputRuntime::ensureLinked/s',
            $jit,
            'JIT ECHO TYPE_NATIVE_BOOL must ensureLinked before bare __phpc_ob_echo_cstr (#34695)'
        );
    }

    public function testNoNewRuntimeCForMinimalObOutputLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist(
            $runtimeDir.'/ob_output.c',
            'must not add ob_output.c for #34695 — PHP JIT bridges only'
        );
    }
}
