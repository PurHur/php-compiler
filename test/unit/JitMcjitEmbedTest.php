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
}
