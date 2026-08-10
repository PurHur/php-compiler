<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\JitMcjitEmbed */
final class JitMcjitEmbedTest extends TestCase
{
    public function testPadsEmptyUserClassBodyForMcjit(): void
    {
        $in = <<<'PHP'
<?php
class Foo {}
$o = new Foo();
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpcMcjitClassPad', $out);
        $this->assertStringNotContainsString("class Foo {}\n", $out);
    }

    public function testPadsConstOnlyUserClassBodyForMcjit(): void
    {
        $in = <<<'PHP'
<?php
class C {
    public const X = 1;
}
echo C::X;
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpcMcjitClassPad', $out);
        $this->assertStringContainsString('public const X = 1', $out);
    }

    public function testLeavesNonEmptyClassUnchanged(): void
    {
        $in = <<<'PHP'
<?php
class Foo { public int $x = 1; }
PHP;
        $this->assertSame($in, JitMcjitEmbed::prepareClassless($in));
    }

    public function testInjectsBootstrapWhenNoUserClass(): void
    {
        $in = '<?php echo 1;';
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertStringContainsString("} \n", $out);
    }

    /** @covers issue #28002 — bootstrap must not precede bracketed multi-namespace */
    public function testAppendsBootstrapForBracketedMultiNamespace(): void
    {
        $in = <<<'PHP'
<?php
namespace A {
  function f(){ return 1; }
}
namespace {
  echo \A\f(), PHP_EOL;
}
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertMatchesRegularExpression(
            '/^<\?php\s*\nnamespace A \{/s',
            $out
        );
        $this->assertStringContainsString("namespace { class __phpc_mcjit_embed_bootstrap", $out);
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($out, 'jit-ns-multi.php');
        $this->assertNotNull($block);
    }

    /** @covers issue #28002 — unbracketed namespace: class lands in that namespace at EOF */
    public function testAppendsBootstrapForUnbracketedNamespaceClassless(): void
    {
        $in = <<<'PHP'
<?php
namespace Foo;
function g(){ return 2; }
echo \Foo\g(), PHP_EOL;
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertMatchesRegularExpression(
            '/^<\?php\s*\nnamespace Foo;/s',
            $out
        );
        $this->assertStringNotContainsString('namespace { class __phpc_mcjit_embed_bootstrap', $out);
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($out, 'jit-ns-unbr.php');
        $this->assertNotNull($block);
    }

    /** @covers issue #28002 — relative `namespace\Foo` is not a declaration */
    public function testPrependsBootstrapWhenOnlyNamespaceNameQualifier(): void
    {
        $in = '<?php echo namespace\\strlen("ab");';
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringStartsWith('<?php class __phpc_mcjit_embed_bootstrap', $out);
    }

    /** @covers issue #27156 — `$class` / get_class must not suppress embed bootstrap */
    public function testInjectsBootstrapWhenOnlyClassVariableOrGetClass(): void
    {
        $var = '<?php $class = "stdClass"; echo get_class(new $class), "\n";';
        $out = JitMcjitEmbed::prepareClassless($var);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);

        $getClass = '<?php echo get_class(new stdClass), "\n";';
        $out2 = JitMcjitEmbed::prepareClassless($getClass);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out2);
    }

    /** @covers issue #17150 */
    public function testInjectsBootstrapAfterLeadingInlineHashComments(): void
    {
        $in = "#teste\n#teste2\n<?php\necho 1;\n";
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringStartsWith("#teste\n#teste2\n", $out);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
    }

    public function testBootstrapPrependPreservesUserLineNumbers(): void
    {
        $in = <<<'PHP'
<?php
echo __LINE__, "\n";
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString("} \necho __LINE__", $out);
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($out, 'jit-line-probe.php');
        $this->assertNotNull($block);
        foreach ($block->opCodes as $op) {
            if ($op->type === \PHPCompiler\OpCode::TYPE_SCRIPT_MAGIC
                && $op->arg3 === \PHPCompiler\OpCode::SCRIPT_MAGIC_LINE) {
                $this->assertSame(2, $op->arg2);

                return;
            }
        }
        $this->fail('Missing TYPE_SCRIPT_MAGIC LINE opcode');
    }

    public function testInjectsBootstrapForEnumOnlyScript(): void
    {
        $in = <<<'PHP'
<?php
enum U { case A; case B; }
echo count(U::cases());
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertStringContainsString('enum U', $out);
    }

    /** @covers issue #27012 */
    public function testInjectsBootstrapForInterfaceOnlyScript(): void
    {
        $in = <<<'PHP'
<?php
interface I {}
var_export(interface_exists('I'));
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertStringContainsString('interface I', $out);
    }

    /** @covers issue #27012 */
    public function testDoesNotDoubleBootstrapWhenInterfacePlusPaddedClass(): void
    {
        $in = <<<'PHP'
<?php
interface I {}
class C {}
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpcMcjitClassPad', $out);
        $this->assertStringNotContainsString('__phpc_mcjit_embed_bootstrap', $out);
    }

    /** @covers issue #25929 — docblock "class constant" must not pad a following enum */
    public function testDoesNotPadEnumWhenDocblockMentionsClassConstant(): void
    {
        $in = <<<'PHP'
<?php
/**
 * Fatal Cannot redefine class constant E::a
 */
enum E
{
    case A;
    case a;
}
echo E::A->name;
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringNotContainsString('__phpcMcjitClassPad', $out);
        $this->assertStringContainsString('enum E', $out);
        $this->assertStringContainsString('case A;', $out);
        $this->assertStringContainsString('case a;', $out);
    }

    /** @covers issue #25929 — const-only class still gets MCJIT pad when docblock says "class constant" */
    public function testPadsConstOnlyClassDespiteClassConstantDocblock(): void
    {
        $in = <<<'PHP'
<?php
/**
 * Cannot redefine class constant C::a
 */
class C
{
    public const A = 1;
    public const a = 2;
}
echo C::A;
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpcMcjitClassPad', $out);
        $this->assertStringContainsString('public const A = 1', $out);
        $this->assertStringContainsString('public const a = 2', $out);
    }

    public function testPadsConstructorPromotedOnlyUserClassForMcjit(): void
    {
        $in = <<<'PHP'
<?php
class R {
    public function __construct(public int $x) {}
}
$r = new R(1);
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpcMcjitClassPad', $out);
    }

    /** @covers issue #27163 — nested Closure braces must not skip the MCJIT empty-class pad */
    public function testPadsPropertylessClassContainingNestedClosure(): void
    {
        $in = <<<'PHP'
<?php
class A {
    public function f() {
        $c = function () {
            return get_class($this);
        };

        return $c();
    }
}
var_export((new A())->f());
echo "\n";
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpcMcjitClassPad', $out);
        $this->assertStringContainsString('get_class($this)', $out);
    }

    public function testPrependsBootstrapForReadonlyPromotedOnlyClass(): void
    {
        $in = <<<'PHP'
<?php
readonly class R {
    public function __construct(public int $x) {}
}
$r = new R(1);
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertStringNotContainsString('__phpcMcjitClassPad', $out);
    }

    /** @covers issue #29250 — anonymous readonly + promoted-only needs MCJIT bootstrap */
    public function testPrependsBootstrapForAnonymousReadonlyPromotedOnlyClass(): void
    {
        $in = <<<'PHP'
<?php
$o = new readonly class {
    public function __construct(public int $x = 1) {}
};
$o->x = 2;
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertStringNotContainsString('__phpcMcjitClassPad', $out);
    }

    /** @covers issue #29250 — anonymous promoted-only (non-readonly) gets class pad */
    public function testPadsAnonymousPromotedOnlyClass(): void
    {
        $in = <<<'PHP'
<?php
$o = new class {
    public function __construct(public int $x = 1) {}
};
echo $o->x;
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpcMcjitClassPad', $out);
        $this->assertStringNotContainsString('__phpc_mcjit_embed_bootstrap', $out);
    }

    /** @covers issue #8967 */
    public function testPadsEmptyReadonlyClassWithoutPropertyDefault(): void
    {
        $in = <<<'PHP'
<?php
readonly class R {}
$o = new R();
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('private bool $__phpcMcjitClassPad;', $out);
        $this->assertStringNotContainsString('__phpcMcjitClassPad = false', $out);
    }

    /** @covers issue #26424 / #29030 — string/eval payloads are not class decls; still need MCJIT bootstrap */
    public function testDoesNotPadClassInsideDoubleQuotedString(): void
    {
        $in = <<<'PHP'
<?php
$code = "class PadProbe {}";
eval($code);
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertStringContainsString('$code = "class PadProbe {}";', $out);
        $this->assertStringNotContainsString('__phpcMcjitClassPad', $out);
    }

    /** @covers issue #26424 / #29030 */
    public function testDoesNotPadClassInsideSingleQuotedEvalString(): void
    {
        $in = <<<'PHP'
<?php
eval('class PadProbeSq {}');
echo class_exists('PadProbeSq') ? "ok\n" : "no\n";
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertStringContainsString("eval('class PadProbeSq {}');", $out);
        $this->assertStringNotContainsString('__phpcMcjitClassPad', $out);
    }

    /** @covers issue #29030 — Dom classList-style error strings must not suppress MCJIT bootstrap */
    public function testInjectsBootstrapWhenClassKeywordOnlyInsideStringLiteral(): void
    {
        $in = <<<'PHP'
<?php
$x = "class after ";
echo "ok\n";
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString('__phpc_mcjit_embed_bootstrap', $out);
        $this->assertStringContainsString('$x = "class after ";', $out);
    }

    /** @covers issue #26424 — real top-level empty class still needs the MCJIT pad */
    public function testStillPadsTopLevelEmptyClassAlongsideEvalString(): void
    {
        $in = <<<'PHP'
<?php
eval('class FromEval {}');
class TopLevel {}
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringContainsString("__phpcMcjitClassPad", $out);
        $this->assertStringContainsString("eval('class FromEval {}')", $out);
        $this->assertDoesNotMatchRegularExpression(
            "/eval\\('class FromEval \\{[^']*__phpcMcjitClassPad/",
            $out
        );
    }

    /** @covers issue #10312 */
    public function testEmbedClassPadHiddenFromVarExport(): void
    {
        $code = JitMcjitEmbed::prepareClassless(<<<'PHP'
<?php
class Foo { public function __construct(public int $x = 0) {} }
class D { public const Y = new Foo(7); }
var_export(D::Y);
PHP);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'class_const_pad_var_export.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString("'x' => 7", $out);
        $this->assertStringNotContainsString('__phpcMcjitClassPad', $out);
    }

    /** @covers issue #29665 — asymmetric private(set) is a real property; do not MCJIT-pad */
    public function testDoesNotPadClassWithPrivateSetProperty(): void
    {
        $in = <<<'PHP'
<?php
class A {
    public private(set) string $x = "a";
}
class B extends A {
    public function setX(string $v): void
    {
        $this->x = $v;
    }
}
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringNotContainsString(
            'class A { private bool $__phpcMcjitClassPad',
            $out
        );
        $this->assertStringContainsString('public private(set) string $x', $out);
        // B is propertyless — still padded, but body newlines must remain so assign stays on line 8.
        $this->assertStringContainsString('__phpcMcjitClassPad', $out);
        $lines = explode("\n", $out);
        $this->assertSame('        $this->x = $v;', $lines[7] ?? '');
    }

    /** @covers issue #29665 — parenthesized asymmetric set form */
    public function testDoesNotPadClassWithParenthesizedPrivateSetProperty(): void
    {
        $in = <<<'PHP'
<?php
class Demo {
    public (private(set)) string $name = 'x';
}
PHP;
        $out = JitMcjitEmbed::prepareClassless($in);
        $this->assertStringNotContainsString('__phpcMcjitClassPad', $out);
        $this->assertStringContainsString('public (private(set)) string $name', $out);
    }
}
