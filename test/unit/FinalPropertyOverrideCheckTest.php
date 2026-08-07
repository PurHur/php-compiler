<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #22241, #22308 */
final class FinalPropertyOverrideCheckTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }

    public function testPlainFinalPropertyCompilesAndReads(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class ParentF {
    final public string $name = 'a';
}
echo (new ParentF)->name, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_plain_ok.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("a\n", ob_get_clean());
    }

    /**
     * @covers issue #26339 — PROFILE=8.4 issue-body: isFinal=1 + eval override Fatal
     * (php-src Zend/zend_inheritance.c + ext/reflection/php_reflection.c).
     */
    public function testPlainFinalPropertyIsFinalAndOverrideFatalUnderProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        self::assertTrue(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public final string $x = 'a';
}
$r = new ReflectionProperty('A', 'x');
echo 'isFinal=', $r->isFinal() ? '1' : '0', "\n";
eval('class B extends A { public string $x = "b"; }');
echo "override_ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_plain_profile84.php');
        try {
            ob_start();
            $runtime->run($block, false);
            ob_end_clean();
            $this->fail('Expected Fatal on final property override under PROFILE=8.4');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $out = ob_get_clean();
            self::assertStringContainsString('isFinal=1', (string) $out);
            self::assertStringContainsString('Cannot override final property A::$x', $e->getMessage());
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            $out = ob_get_clean();
            self::assertSame(255, $e->status);
            self::assertStringContainsString('isFinal=1', (string) $out);
            self::assertStringNotContainsString('override_ok', (string) $out);
        }
    }

    /**
     * @covers issue #26306 / #26339 — PROFILE=8.5 keeps isFinal + override fatal (same as 8.4)
     */
    public function testPlainFinalPropertyIsFinalAndOverrideFatalUnderProfile85(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.5';
        self::assertTrue(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public final string $x = 'a';
}
$r = new ReflectionProperty('A', 'x');
echo 'isFinal=', $r->isFinal() ? '1' : '0', "\n";
eval('class B extends A { public string $x = "b"; }');
echo "override_ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_plain_profile85.php');
        try {
            ob_start();
            $runtime->run($block, false);
            ob_end_clean();
            $this->fail('Expected Fatal on final property override under PROFILE=8.5');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $out = ob_get_clean();
            self::assertStringContainsString('isFinal=1', (string) $out);
            self::assertStringContainsString('Cannot override final property A::$x', $e->getMessage());
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            $out = ob_get_clean();
            self::assertSame(255, $e->status);
            self::assertStringContainsString('isFinal=1', (string) $out);
            self::assertStringNotContainsString('override_ok', (string) $out);
        }
    }

    /**
     * @covers issue #26222 / #22341 — ReflectionProperty::IS_FINAL must resolve from the
     * VM ClassEntry (case-sensitive keys, #25910), not only via host native fallback.
     * Plain final remains inheritance-only for writes (php-src-strict, #23683).
     */
    public function testReflectionPropertyIsFinalConstantAndModifiers(): void
    {
        self::assertTrue(\PHPCompiler\CompilerVersion::supportsFinalProperties());
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public final int $x = 1;
}
$r = new ReflectionProperty('C', 'x');
echo 'isFinal=', $r->isFinal() ? '1' : '0', "\n";
echo 'IS_FINAL=', ReflectionProperty::IS_FINAL, "\n";
echo 'bit=', ($r->getModifiers() & ReflectionProperty::IS_FINAL) ? '1' : '0', "\n";
$c = new C();
$c->x = 2;
echo 'wrote=', $c->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_plain_is_final_const.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("isFinal=1\nIS_FINAL=32\nbit=1\nwrote=2\n", ob_get_clean());
    }

    public function testPlainFinalPropertyRejectedOnReferenceProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        self::assertFalse(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public final string $x = 'a';
}
PHP;
        try {
            $runtime->parseAndCompile($code, 'final_plain_reject_ref.php');
            $this->fail('Expected CompileFatal on reference profile');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            self::assertStringContainsString(
                'Cannot declare property C::$x final, the final modifier is allowed only for methods, classes, and class constants',
                $e->getMessage()
            );
            self::assertStringStartsWith('PHP Fatal error:', $e->zendStderrLine());
        }
    }

    /**
     * @covers issue #28149 — issue-body profile table: reference reject + PROFILE≥8.4
     * isFinal=1 + child override Fatal (test/repro/maintainer_gap_final_plain_property_profile.php).
     */
    public function testIssue28149ProfileTableReferenceRejectAndProfile84IsFinalOverride(): void
    {
        $repro = dirname(__DIR__) . '/repro/maintainer_gap_final_plain_property_profile.php';
        self::assertFileExists($repro);
        $code = file_get_contents($repro);
        self::assertNotFalse($code);

        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        self::assertFalse(\PHPCompiler\CompilerVersion::supportsFinalProperties());
        $runtimeRef = new Runtime();
        try {
            $runtimeRef->parseAndCompile($code, 'issue28149_profile_ref.php');
            $this->fail('Expected CompileFatal on reference profile for #28149 table');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            self::assertStringContainsString(
                'Cannot declare property A::$x final, the final modifier is allowed only for methods, classes, and class constants',
                $e->getMessage()
            );
        }

        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        self::assertTrue(\PHPCompiler\CompilerVersion::supportsFinalProperties());
        $runtime84 = new Runtime();
        $block = $runtime84->parseAndCompile($code, 'issue28149_profile_84.php');
        try {
            ob_start();
            $runtime84->run($block, false);
            ob_end_clean();
            $this->fail('Expected Fatal on final property override under PROFILE=8.4 (#28149)');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $out = ob_get_clean();
            self::assertStringContainsString("parsed\n", (string) $out);
            self::assertStringContainsString('isFinal=1', (string) $out);
            self::assertStringContainsString('Cannot override final property A::$x', $e->getMessage());
            self::assertStringNotContainsString('child_ok', (string) $out);
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            $out = ob_get_clean();
            self::assertSame(255, $e->status);
            self::assertStringContainsString("parsed\n", (string) $out);
            self::assertStringContainsString('isFinal=1', (string) $out);
            self::assertStringNotContainsString('child_ok', (string) $out);
        }
    }

    /**
     * @covers issue #25535 — eval() must not accept final plain properties on reference profile
     */
    public function testEvalPlainFinalPropertyRejectedOnReferenceProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        self::assertFalse(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
eval('class T { final public int $x = 1; }');
echo "parsed_ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_plain_eval_reject_ref.php');
        self::assertTrue(\PHPCompiler\Block::requiresVmLowering($block));
        try {
            ob_start();
            $runtime->run($block, false);
            ob_end_clean();
            $this->fail('Expected CompileFatal / ScriptExit for final plain property in eval');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            ob_end_clean();
            self::assertStringContainsString(
                'Cannot declare property T::$x final, the final modifier is allowed only for methods, classes, and class constants',
                $e->getMessage()
            );
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            $out = ob_get_clean();
            self::assertSame(255, $e->status);
            self::assertStringNotContainsString('parsed_ok', (string) $out);
        }
    }

    /**
     * @covers issue #26169 — AOT TYPE_EVAL probe must rethrow CompileFatal (not swallow → parsed_ok)
     */
    public function testEvalProbeRethrowsCompileFatalOnReferenceProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        self::assertFalse(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $probe = new Runtime();
        $literal = 'class T { final public int $x = 1; }';
        $evalFile = \PHPCompiler\ext\standard\VmEval::zendEvalFilename('aot_eval_final.php', 2);

        try {
            \PHPCompiler\ext\standard\VmEval::tryCompileBlockOrThrowCompileFatal($probe, $literal, $evalFile);
            $this->fail('Expected CompileFatal for final plain property in eval probe');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            self::assertStringContainsString(
                'Cannot declare property T::$x final, the final modifier is allowed only for methods, classes, and class constants',
                $e->getMessage()
            );
        }

        // Legacy tryCompileBlock still swallows — AOT must use OrThrowCompileFatal (#26169).
        $swallowed = \PHPCompiler\ext\standard\VmEval::tryCompileBlock(new Runtime(), $literal, $evalFile);
        self::assertNull($swallowed);
    }

    /**
     * @covers issue #24316 — construct + write must never run on reference profile
     * (issue table: declare=ok / write=… is the failure mode when the gate is skipped).
     */
    public function testPlainFinalPropertyConstructWriteRejectedOnReferenceProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        self::assertFalse(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    final public string $x = 'a';
}
$o = new C();
echo "declare=ok value={$o->x}\n";
$o->x = 'b';
echo "write={$o->x}\n";
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(
            'Cannot declare property C::$x final, the final modifier is allowed only for methods, classes, and class constants'
        );
        $runtime->parseAndCompile($code, 'final_plain_construct_write_reject_ref.php');
    }

    /** @covers issue #23403 — static finals must also reject when supportsFinalProperties() is false */
    public function testFinalStaticPropertyRejectedOnReferenceProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        self::assertFalse(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public final static $x = 1;
}
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(
            'Cannot declare property A::$x final, the final modifier is allowed only for methods, classes, and class constants'
        );
        $runtime->parseAndCompile($code, 'final_static_reject_ref.php');
    }

    /** @covers issue #23403 */
    public function testFinalStaticPropertyCompilesOnForwardProfile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public final static $x = 1;
}
echo A::$x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_static_ok.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n", ob_get_clean());
    }

    /** @covers issue #23403 */
    public function testChildCannotOverrideFinalStaticProperty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public final static $x = 1;
}
class B extends A {
    public static $x = 2;
}
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('Cannot override final property A::$x');
        $runtime->parseAndCompile($code, 'final_static_override.php');
    }

    public function testChildCannotOverrideFinalProperty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class ParentF {
    final public string $name = 'a';
}
class ChildF extends ParentF {
    public string $name = 'b';
}
PHP;
        try {
            $runtime->parseAndCompile($code, 'final_plain_override.php');
            $this->fail('Expected CompileFatal on final property override');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            self::assertStringContainsString('Cannot override final property ParentF::$name', $e->getMessage());
            self::assertStringStartsWith('PHP Fatal error:', $e->zendStderrLine());
            self::assertStringContainsString('on line', $e->zendStderrLine());
        }
    }

    public function testHookedFinalPropertyStillWorks(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public final string $label {
        get => 'ok';
    }
}
echo (new C)->label;
PHP;
        $block = $runtime->parseAndCompile($code, 'final_hooked_ok.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('ok', ob_get_clean());
    }

    /** @covers issue #22988 — cross-eval must hit inheritFromParent, not only same-script FinalPropertyOverrideCheck */
    public function testEvalCannotOverrideFinalPlainProperty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    final public string $x = 'a';
}
eval('class B extends A { public string $x = "b"; }');
echo "EVAL_OK\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_plain_eval_override.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final property A::$x');
        $runtime->run($block);
    }

    /** @covers issue #22474 */
    public function testChildCannotOverrideFinalSetHook(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class P {
    public string $x {
        get => 'p';
        final set(string $v) {}
    }
}
class C extends P {
    public string $x {
        get => 'c';
        set(string $v) {}
    }
}
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('Cannot override final property hook P::$x::set()');
        $runtime->parseAndCompile($code, 'final_hook_set_override.php');
    }

    /** @covers issue #22474 */
    public function testChildCannotOverrideFinalGetHook(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class P {
    public string $x {
        final get => 'p';
        set(string $v) {}
    }
}
class C extends P {
    public string $x {
        get => 'c';
        set(string $v) {}
    }
}
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('Cannot override final property hook P::$x::get()');
        $runtime->parseAndCompile($code, 'final_hook_get_override.php');
    }

    /** @covers issue #22474 */
    public function testNonFinalHookOverrideStillWorks(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class P {
    public string $x {
        get => 'p';
        set(string $v) { $this->x = $v; }
    }
}
class C extends P {
    public string $x {
        get => 'c';
        set(string $v) { $this->x = $v; }
    }
}
echo (new C)->x;
PHP;
        $block = $runtime->parseAndCompile($code, 'nonfinal_hook_override.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('c', ob_get_clean());
    }

    /**
     * @covers issue #24770 / #27122 — ternary/branch between class decls moves the
     * child Class_ into a successor CFG block; collect() must still see the override.
     */
    public function testChildCannotOverrideFinalPropertyAfterTernary(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public final string $x = 'a';
}
true ? 1 : 0;
echo 'instance_isFinal=', (new ReflectionProperty('A', 'x'))->isFinal() ? '1' : '0', "\n";
class S {
    public final static string $s = 's';
}
false ? 1 : 0;
echo 'static_isFinal=', (new ReflectionProperty('S', 's'))->isFinal() ? '1' : '0', "\n";
true ? 1 : 0;
class B extends A {
    public string $x = 'b';
}
echo "override_allowed=1\n";
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('Cannot override final property A::$x');
        $runtime->parseAndCompile($code, 'final_plain_override_after_ternary.php');
    }

    /**
     * @covers issue #27122 — same-script issue-body: isFinal=true + compile Fatal on
     * child redeclaration (not only the eval() inheritFromParent path).
     */
    public function testIssue27122SameScriptIsFinalAndOverrideCompileFatal(): void
    {
        self::assertTrue(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $isFinalCode = <<<'PHP'
<?php
class C { public final string $name = 'x'; }
var_export((new ReflectionProperty(C::class, 'name'))->isFinal());
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($isFinalCode, 'issue27122_isFinal.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("true\n", ob_get_clean());

        $overrideCode = <<<'PHP'
<?php
class C { public final string $name = 'x'; }
class D extends C { public string $name = 'y'; }
echo (new D())->name, "\n";
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('Cannot override final property C::$name');
        $runtime->parseAndCompile($overrideCode, 'issue27122_override.php');
    }

    /** @covers issue #22474 — final set does not block overriding get alone */
    public function testOverrideGetWhenOnlySetIsFinal(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class P {
    public string $x {
        get => 'p';
        final set(string $v) { $this->x = $v; }
    }
}
class C extends P {
    public string $x {
        get => 'c';
    }
}
echo (new C)->x;
PHP;
        $block = $runtime->parseAndCompile($code, 'final_set_override_get_only.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('c', ob_get_clean());
    }

    /**
     * @covers issue #28078 — PROFILE=8.4 issue-body: try/catch post-construct write
     * still reaches isFinal=1 (AOT previously ret-void'd after the write).
     */
    public function testPlainFinalPropertyTryWriteThenIsFinalUnderProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        self::assertTrue(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    final public string $x;
    public function __construct(string $x) { $this->x = $x; }
}
$a = new A('a');
try { $a->x = 'b'; echo "wrote\n"; } catch (Throwable $e) { echo "write_err\n"; }
$r = new ReflectionProperty(A::class, 'x');
echo 'isFinal=', $r->isFinal() ? '1' : '0', "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_plain_try_profile84.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("wrote\nisFinal=1\n", ob_get_clean());
    }

    /**
     * @covers issue #28393 — exact issue-body repros A+B (final public write/isFinal,
     * then non-eval child redeclaration Fatal). Durable guard against false refiles.
     */
    public function testIssue28393BodyWriteIsFinalAndDirectChildOverrideFatal(): void
    {
        $isfinal = dirname(__DIR__) . '/repro/issue_28393_final_plain_isfinal.php';
        $override = dirname(__DIR__) . '/repro/issue_28393_final_plain_override.php';
        self::assertFileExists($isfinal);
        self::assertFileExists($override);
        $isfinalCode = file_get_contents($isfinal);
        $overrideCode = file_get_contents($override);
        self::assertNotFalse($isfinalCode);
        self::assertNotFalse($overrideCode);

        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        self::assertTrue(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($isfinalCode, 'issue_28393_isfinal.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("wrote\nisFinal=1\n", ob_get_clean());

        try {
            $runtime->parseAndCompile($overrideCode, 'issue_28393_override.php');
            $this->fail('Expected CompileFatal on direct child override (#28393)');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            self::assertStringContainsString('Cannot override final property A::$x', $e->getMessage());
            self::assertStringStartsWith('PHP Fatal error:', $e->zendStderrLine());
        }
    }

    /**
     * @covers issue #28523 — issue-body: isFinal=1 + WRITE_OK (Zend inheritance-only)
     * and sibling-class override CompileFatal under PROFILE=8.4 / 8.5.
     * Re-#28437 / re-#28393; write cell in the issue table was wrong vs php:8.4-cli.
     */
    public function testIssue28523IssueBodyIsFinalWriteAndSiblingOverride(): void
    {
        $isfinal = dirname(__DIR__) . '/repro/issue_28523_final_plain_isfinal_write.php';
        $override = dirname(__DIR__) . '/repro/issue_28523_final_plain_property_84.php';
        self::assertFileExists($isfinal);
        self::assertFileExists($override);
        $isfinalCode = file_get_contents($isfinal);
        $overrideCode = file_get_contents($override);
        self::assertNotFalse($isfinalCode);
        self::assertNotFalse($overrideCode);

        foreach (['8.4', '8.5'] as $profile) {
            putenv('PHP_COMPILER_PROFILE='.$profile);
            $_ENV['PHP_COMPILER_PROFILE'] = $profile;
            self::assertTrue(
                \PHPCompiler\CompilerVersion::supportsFinalProperties(),
                'PROFILE='.$profile.' must support final properties'
            );

            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($isfinalCode, 'issue_28523_isfinal_'.$profile.'.php');
            ob_start();
            $runtime->run($block);
            self::assertSame("isFinal=1\nWRITE_OK\n", ob_get_clean(), 'PROFILE='.$profile);

            try {
                $runtime->parseAndCompile($overrideCode, 'issue_28523_override_'.$profile.'.php');
                $this->fail('Expected CompileFatal on sibling override (#28523) PROFILE='.$profile);
            } catch (\PHPCompiler\Compiler\CompileFatal $e) {
                self::assertStringContainsString('Cannot override final property A::$x', $e->getMessage());
                self::assertStringStartsWith('PHP Fatal error:', $e->zendStderrLine());
            }
        }
    }

    /**
     * @covers issue #28437 — AOT TYPE_EVAL must Fatal on outer-unit final property
     * override (not emitFalse → redef_ok). VM/JIT already inheritFromParent (#22988).
     */
    public function testIssue28437AotEvalOverrideFatalUnderProfile84(): void
    {
        $repro = dirname(__DIR__) . '/repro/issue_28437_final_plain_eval_override.php';
        self::assertFileExists($repro);
        $code = file_get_contents($repro);
        self::assertNotFalse($code);

        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        self::assertTrue(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        // VM: isFinal then inheritFromParent Fatal.
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_28437_vm.php');
        try {
            ob_start();
            $runtime->run($block, false);
            ob_end_clean();
            $this->fail('Expected Fatal on eval final property override under PROFILE=8.4 (#28437)');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $out = ob_get_clean();
            self::assertStringContainsString('isFinal=1', (string) $out);
            self::assertStringContainsString('Cannot override final property A::$x', $e->getMessage());
            self::assertStringNotContainsString('redef_ok', (string) $out);
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            $out = ob_get_clean();
            self::assertSame(255, $e->status);
            self::assertStringContainsString('isFinal=1', (string) $out);
            self::assertStringNotContainsString('redef_ok', (string) $out);
        } catch (\CompileError $e) {
            $out = ob_get_clean();
            self::assertStringContainsString('isFinal=1', (string) $out);
            self::assertStringContainsString('Cannot override final property A::$x', $e->getMessage());
            self::assertStringNotContainsString('redef_ok', (string) $out);
        }

        // AOT emit path: EvalRuntime must surface CompileFatal before emitFalse (#28437).
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available — VM path covered; AOT emit needs Docker');
        }
        try {
            $aot = new Runtime(Runtime::MODE_AOT);
            $aotBlock = $aot->parseAndCompile($code, 'issue_28437_aot.php');
            $aot->jit($aotBlock);
            $this->fail('Expected CompileFatal during AOT emit of eval final override (#28437)');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            self::assertStringContainsString('Cannot override final property A::$x', $e->getMessage());
        } catch (\FFI\Exception $e) {
            $this->markTestSkipped('LLVM FFI unavailable on host: '.$e->getMessage());
        }
    }

    /**
     * @covers issue #27818 — trait-imported final plain property: isFinal=1 + child
     * override CompileFatal (same Zend inheritance rules as class-declared finals).
     */
    public function testTraitImportedFinalPropertyIsFinalAndOverrideCompileFatal(): void
    {
        self::assertTrue(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $isFinalCode = <<<'PHP'
<?php
trait T { final public string $x = 't'; }
class A { use T; }
echo 'isFinal=', (int) (new ReflectionProperty(A::class, 'x'))->isFinal(), "\n";
$a = new A();
$a->x = 'z';
echo 'wrote=', $a->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($isFinalCode, 'issue27818_trait_isFinal.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("isFinal=1\nwrote=z\n", ob_get_clean());

        $overrideCode = <<<'PHP'
<?php
trait T { final public string $x = 't'; }
class A { use T; }
class B extends A { public string $x = 'b'; }
echo "bad\n";
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('Cannot override final property A::$x');
        $runtime->parseAndCompile($overrideCode, 'issue27818_trait_override.php');
    }
}
