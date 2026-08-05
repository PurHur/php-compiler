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
}
