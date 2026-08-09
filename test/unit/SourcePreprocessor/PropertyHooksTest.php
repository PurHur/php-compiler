<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\SourcePreprocessor;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CompilerVersion;
use PHPCompiler\PropertyHookSyntaxRejector;
use PHPCompiler\Runtime;
use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

final class PropertyHooksTest extends TestCase
{
    use PropertyHookTestSkip;

    public function testStripsSetHookAndInjectsMethod(): void
    {
        $src = <<<'PHP'
<?php
class User {
    public string $email {
        set (string $value) {
            $this->email = $value;
        }
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$email {', $out);
        self::assertStringContainsString('public string $email;', $out);
        self::assertStringContainsString('function __phpc_property_set_email', $out);
    }

    public function testLowersArrowGetAndSetHooks(): void
    {
        $src = <<<'PHP'
<?php
class Box {
    public string $label {
        get => strtoupper($this->label);
        set => $value = trim($value);
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('function __phpc_property_get_label', $out);
        self::assertStringContainsString('return strtoupper($this->label);', $out);
        self::assertStringContainsString('function __phpc_property_set_label', $out);
        self::assertStringContainsString('$this->label = ($value = trim($value));', $out);
    }

    public function testMarksVirtualWhenHooksDoNotTouchBacking(): void
    {
        $src = <<<'PHP'
<?php
class Box {
    private string $label = 'ok';
    public string $name {
        get => $this->label;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('function __phpc_property_get_name', $out);
        self::assertTrue($registry['box']['name']['virtual'] ?? false);
    }

    public function testLowersShortGetHookOnTypedProperty(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public int $p {
        get => 1;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$p {', $out);
        self::assertStringContainsString('public int $p;', $out);
        self::assertStringContainsString('function __phpc_property_get_p', $out);
        self::assertStringContainsString('{ return 1; }', $out);
        self::assertSame('__phpc_property_get_p', $registry['c']['p']['get'] ?? null);
    }

    /** @covers issue #21098 — `&get` lowers to return-by-ref hook method */
    public function testLowersByRefGetHookArrowAndBlock(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    private array $a = [1];
    public array $x {
        &get => $this->a;
    }
}
class D {
    private array $a = [1];
    public array $y {
        &get {
            return $this->a;
        }
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('&get', $out);
        self::assertStringContainsString('function &__phpc_property_get_x()', $out);
        self::assertStringContainsString('function &__phpc_property_get_y()', $out);
        self::assertTrue($registry['c']['x']['getByRef'] ?? false);
        self::assertSame('a', $registry['c']['x']['getBacking'] ?? null);
        self::assertTrue($registry['d']['y']['getByRef'] ?? false);
    }

    public function testStripsAbstractGetHookOnInterface(): void
    {
        $src = <<<'PHP'
<?php
interface HasTitle {
    public string $title {
        get;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$title {', $out);
        self::assertStringContainsString('public string $title;', $out);
        self::assertStringNotContainsString('__phpc_property_get_title', $out);
        self::assertTrue($registry['hastitle']['title']['virtual'] ?? false);
    }

    public function testStripsAbstractGetSetHooksOnInterface(): void
    {
        $src = <<<'PHP'
<?php
interface I {
    public int $p {
        get;
        set;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$p {', $out);
        self::assertStringContainsString('public int $p;', $out);
        self::assertStringNotContainsString('set;', $out);
        self::assertTrue($registry['i']['p']['virtual'] ?? false);
        self::assertTrue($registry['i']['p']['requiresGet'] ?? false);
        self::assertTrue($registry['i']['p']['requiresSet'] ?? false);
    }

    public function testIgnoresInterfaceKeywordInComments(): void
    {
        $src = <<<'PHP'
<?php
/** interface property hooks in comments must not confuse the scanner */
interface I {
    public int $x {
        get;
        set;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertTrue($registry['i']['x']['requiresGet'] ?? false);
        self::assertTrue($registry['i']['x']['requiresSet'] ?? false);
        self::assertArrayNotHasKey('property', $registry);
    }

    public function testStripsAbstractGetHookOnAbstractClass(): void
    {
        $src = <<<'PHP'
<?php
abstract class A {
    abstract public string $label {
        get;
    }
}
final class C extends A {
    public string $label {
        get => 'child';
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$label {', $out);
        self::assertStringContainsString('public string $label;', $out);
        self::assertStringNotContainsString('abstract public string $label', $out);
        self::assertStringContainsString('function __phpc_property_get_label', $out);
        self::assertTrue($registry['a']['label']['abstract'] ?? false);
        self::assertSame('__phpc_property_get_label', $registry['c']['label']['get'] ?? null);
    }

    public function testRegistersSemicolonGetHookOnConcreteClass(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public string $p {
        get;
    }
}
PHP;
        [, $registry] = (new PropertyHooks())->process($src);
        self::assertTrue($registry['c']['p']['requiresGet'] ?? false);
        self::assertTrue($registry['c']['p']['abstract'] ?? false);
    }

    public function testRegistersSemicolonHooksOnAbstractClassWithoutAbstractProperty(): void
    {
        $src = <<<'PHP'
<?php
abstract class A {
    public string $p {
        get;
        set;
    }
}
PHP;
        [, $registry] = (new PropertyHooks())->process($src);
        self::assertTrue($registry['a']['p']['requiresGet'] ?? false);
        self::assertTrue($registry['a']['p']['requiresSet'] ?? false);
    }

    public function testLowersTraitPropertyHooks(): void
    {
        $src = <<<'PHP'
<?php
trait T {
    public string $x {
        get => $this->__x;
        set(string $v) { $this->__x = $v; }
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('public string $x;', $out);
        self::assertStringContainsString('function __phpc_property_get_x', $out);
        self::assertStringContainsString('function __phpc_property_set_x', $out);
        self::assertSame('__phpc_property_get_x', $registry['t']['x']['get'] ?? null);
    }

    public function testTraitAbstractPropertyHooksRegisterRequirements(): void
    {
        $src = <<<'PHP'
<?php
trait T {
    public string $x { get; set; }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('public string $x;', $out);
        self::assertStringNotContainsString('__phpc_property_get_x', $out);
        self::assertTrue($registry['t']['x']['requiresGet'] ?? false);
        self::assertTrue($registry['t']['x']['requiresSet'] ?? false);
        self::assertTrue($registry['t']['x']['abstract'] ?? false);
    }

    public function testLowersSetArrowAssignmentToSeparateBackingField(): void
    {
        $src = <<<'PHP'
<?php
class H {
    public int $x {
        get => $this->v;
        set => $this->v = $value;
    }
    private int $v = 1;
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('$this->v = $value;', $out);
        self::assertStringNotContainsString('$this->x =', $out);
        self::assertTrue($registry['h']['x']['virtual'] ?? false);
    }

    public function testLowersSetArrowTransformToSeparateBackingField(): void
    {
        $src = <<<'PHP'
<?php
class Box {
    private int $stored = 0;
    public int $value {
        get => $this->stored;
        set => $this->stored = $value * 10;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('$this->stored = $value * 10;', $out);
        self::assertStringNotContainsString('$this->value =', $out);
        self::assertTrue($registry['box']['value']['virtual'] ?? false);
    }

    public function testLowersSetParamArrowHook(): void
    {
        $src = <<<'PHP'
<?php
class Box {
    private string $stored = 'init';
    public string $x {
        get => $this->stored;
        set(string $v) => $this->stored = strtoupper($v);
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$x {', $out);
        self::assertStringContainsString('public string $x;', $out);
        self::assertStringContainsString('function __phpc_property_set_x(string $v)', $out);
        self::assertStringContainsString('$this->stored = strtoupper($v);', $out);
        self::assertSame('__phpc_property_set_x', $registry['box']['x']['set'] ?? null);
    }

    public function testLowersGetParamBlockHook(): void
    {
        $src = <<<'PHP'
<?php
class C {
    private string $_data = 'abcdef';
    public string $chunk {
        get ($len) {
            return substr($this->_data, 0, $len);
        }
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$chunk {', $out);
        self::assertStringContainsString('public string $chunk;', $out);
        self::assertStringContainsString('function __phpc_property_get_chunk($len)', $out);
        self::assertStringContainsString('return substr($this->_data, 0, $len);', $out);
        self::assertSame('__phpc_property_get_chunk', $registry['c']['chunk']['get'] ?? null);
        self::assertTrue($registry['c']['chunk']['getParameterized'] ?? false);
    }

    public function testLowersGetParamArrowHook(): void
    {
        $src = <<<'PHP'
<?php
class C {
    private string $_data = 'abcdef';
    public string $chunk {
        get ($len) => substr($this->_data, 0, $len);
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('function __phpc_property_get_chunk($len)', $out);
        self::assertStringContainsString('return substr($this->_data, 0, $len);', $out);
        self::assertTrue($registry['c']['chunk']['getParameterized'] ?? false);
    }

    public function testRejectsStaticPropertyHooksOnForwardProfile(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class Box {
    public static string $label {
        get => self::$v;
        set => strtoupper($value);
    }
    private static ?string $v = null;
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::STATIC_HOOK_COMPILE_ERROR);
        (new PropertyHooks())->process($src, 'static_hooks.php');
    }

    public function testLowersSetBlockHookWithNestedBackingField(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public int $x {
        set { $this->v = $value; }
        private int $v = 0;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('public int $x;', $out);
        self::assertStringContainsString('private int $v = 0;', $out);
        self::assertStringContainsString('function __phpc_property_set_x', $out);
        self::assertStringContainsString('$this->v = $value;', $out);
        self::assertSame('__phpc_property_set_x', $registry['c']['x']['set'] ?? null);
        self::assertSame('v', $registry['c']['x']['setBacking'] ?? null);
    }

    public function testLowersUnsetHook(): void
    {
        $src = <<<'PHP'
<?php
class Box {
    public string $label {
        get => $this->label ?? 'default';
        set => $this->label = $value;
        unset => $this->label = 'cleared';
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('function __phpc_property_unset_label', $out);
        self::assertStringContainsString("\$this->label = 'cleared';", $out);
        self::assertSame('__phpc_property_unset_label', $registry['box']['label']['unset'] ?? null);
    }

    /** @covers issue #6650 — block hook syntax must preprocess before curly-brace rejector */
    public function testBlockGetHookSurvivesRuntimePreprocess(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public int $x {
        get {
            return 42;
        }
    }
}
PHP;
        $runtime = new Runtime();
        [$out] = $runtime->preprocessSourceForParse($src, 'block_hook.php');
        self::assertStringNotContainsString('$x {', $out);
        self::assertStringContainsString('function __phpc_property_get_x', $out);
    }

    /** @covers issue #6898 — asymmetric set visibility on property hooks */
    public function testLowersAsymmetricSetArrowHook(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public string $x {
        get => 'g';
        set (protected) => $value;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$x {', $out);
        self::assertStringContainsString('/*phpc-asymmetric-set:protected*/ /*phpc-asymmetric-explicit-read*/ public string $x;', $out);
        self::assertStringContainsString('function __phpc_property_get_x', $out);
        self::assertStringContainsString('function __phpc_property_set_x', $out);
        self::assertStringContainsString('$this->x = ($value);', $out);
        self::assertSame('__phpc_property_set_x', $registry['c']['x']['set'] ?? null);
    }

    public function testLowersAsymmetricSetBlockHook(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public string $x {
        get => 'g';
        set (private) {
            $this->x = $value;
        }
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('/*phpc-asymmetric-set:private*/ /*phpc-asymmetric-explicit-read*/ public string $x;', $out);
        self::assertStringContainsString('function __phpc_property_set_x($value)', $out);
    }

    /** @covers issue #7148 / #29388 — brace hook `private set;` is illegal on Zend (visibility on hook) */
    public function testRejectsBraceHookPrivateSetModifierOnConcreteClass(): void
    {
        $src = <<<'PHP'
<?php
class User {
    public string $email { get; private set; }
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(sprintf(PropertyHooks::HOOK_VISIBILITY_MODIFIER_COMPILE_ERROR, 'private'));
        (new PropertyHooks())->process($src, 'private_set_semi.php');
    }

    /** @covers issue #29426 — get-only virtual + decl-site aviz is Zend Fatal (supersedes #13983 allow) */
    public function testRejectsAsymmetricDeclSetOnGetOnlyVirtual(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        foreach (['private', 'protected'] as $setVis) {
            $src = <<<PHP
<?php
class C {
    public {$setVis}(set) string \$x {
        get => 'g';
    }
}
PHP;
            try {
                (new PropertyHooks())->process($src, "aviz_get_only_{$setVis}.php");
                self::fail("Expected CompileFatal for public {$setVis}(set) get-only virtual");
            } catch (CompileFatal $e) {
                self::assertSame(
                    PropertyHooks::readonlyVirtualAsymmetricVisibilityCompileError('C', 'x'),
                    $e->getMessage()
                );
            }
        }
    }

    /** @covers issue #29426 — parenthesized aviz + get-only virtual also Fatal */
    public function testRejectsParenthesizedAsymmetricDeclSetOnGetOnlyVirtual(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public (private(set)) string $x {
        get => 'hi';
    }
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(
            PropertyHooks::readonlyVirtualAsymmetricVisibilityCompileError('C', 'x')
        );
        (new PropertyHooks())->process($src, 'aviz_paren_get_only.php');
    }

    /** @covers issue #29426 — backed get-only (uses $this->prop) keeps aviz legal */
    public function testAllowsAsymmetricDeclSetOnBackedGetOnly(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public private(set) string $x {
        get => $this->x;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$x {', $out);
        self::assertStringContainsString('function __phpc_property_get_x', $out);
        self::assertArrayHasKey('get', $registry['c']['x'] ?? []);
        self::assertArrayNotHasKey('set', $registry['c']['x'] ?? []);
        self::assertFalse($registry['c']['x']['virtual'] ?? false);
    }

    /** @covers issue #12203 — `private(set)` decl + get; obligation without set hook still rejected */
    public function testRejectsAsymmetricDeclSetWithGetSemicolonOnlyHook(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public (private(set)) string $x {
        get;
    }
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::ASYMMETRIC_DECL_SET_REQUIRES_SET_HOOK_MESSAGE);

        (new PropertyHooks())->process($src, 'asymmetric_get_semicolon.php');
    }

    /** @covers issue #12203 / #29388 — in-block `private set;` is rejected; use decl-site aviz + `set;` */
    public function testRejectsAsymmetricDeclSetWithIllegalPrivateSetSemicolon(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public (private(set)) string $x {
        get;
        private set;
    }
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(sprintf(PropertyHooks::HOOK_VISIBILITY_MODIFIER_COMPILE_ERROR, 'private'));
        (new PropertyHooks())->process($src, 'asymmetric_private_set_semi.php');
    }

    /** @covers issue #29388 — PHP 8.4 rejects `private(set);` inside property hook block */
    public function testRejectsHookBlockPrivateSetParenModifier(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public string $x {
        get => 'g';
        private(set);
    }
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(sprintf(PropertyHooks::HOOK_ASYMMETRIC_SET_MODIFIER_COMPILE_ERROR, 'private'));
        (new PropertyHooks())->process($src, 'hook_private_set_paren.php');
    }

    /** @covers issue #29388 — `private set =>` visibility on hook */
    public function testRejectsBraceHookPrivateSetArrowHook(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public string $x {
        get => 'g';
        private set => $this->x = $value;
    }
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(sprintf(PropertyHooks::HOOK_VISIBILITY_MODIFIER_COMPILE_ERROR, 'private'));
        (new PropertyHooks())->process($src, 'private_set_arrow.php');
    }

    /** @covers issue #29388 — `private set(string $v) {}` */
    public function testRejectsPrivateSetBlockHook(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public string $name {
        get => 'g';
        private set(string $v) {}
    }
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(sprintf(PropertyHooks::HOOK_VISIBILITY_MODIFIER_COMPILE_ERROR, 'private'));
        (new PropertyHooks())->process($src, 'private_set_block.php');
    }

    /** @covers issue #29388 — `protected(set);` / `public(set);` inside hooks */
    public function testRejectsProtectedAndPublicSetParenModifiersInHook(): void
    {
        foreach (['protected', 'public'] as $vis) {
            $src = <<<PHP
<?php
class C {
    public string \$x {
        get => 'g';
        {$vis}(set);
    }
}
PHP;
            try {
                (new PropertyHooks())->process($src, "hook_{$vis}_set_paren.php");
                self::fail("Expected CompileFatal for {$vis}(set);");
            } catch (CompileFatal $e) {
                self::assertSame(
                    sprintf(PropertyHooks::HOOK_ASYMMETRIC_SET_MODIFIER_COMPILE_ERROR, $vis),
                    $e->getMessage()
                );
            }
        }
    }

    /** @covers issue #29388 — legal decl-site asymmetric visibility with hooks unchanged */
    public function testLowersLegalDeclSitePrivateSetWithHooks(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public private(set) string $x {
        get => $this->x;
        set => $this->x = $value;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$x {', $out);
        self::assertStringContainsString('function __phpc_property_get_x', $out);
        self::assertStringContainsString('function __phpc_property_set_x', $out);
        self::assertArrayHasKey('get', $registry['c']['x'] ?? []);
        self::assertArrayHasKey('set', $registry['c']['x'] ?? []);
    }

    /** @covers issue #23881 — same-name / short-set backing is not ZEND_ACC_VIRTUAL (Zend isVirtual=false) */
    public function testSameNameBackedHookRegistryNotVirtual(): void
    {
        $src = <<<'PHP'
<?php
class H {
    public string $c { get => $this->c; set => $this->c = $value; }
    public string $d { set => strtoupper($value); }
    public string $a { get => 'x'; set {} }
}
PHP;
        [, $registry] = (new PropertyHooks())->process($src);
        self::assertFalse($registry['h']['c']['virtual'] ?? false);
        self::assertFalse($registry['h']['d']['virtual'] ?? false);
        self::assertTrue($registry['h']['a']['virtual'] ?? false);
    }

    public function testSkipsClassDeclarationsInsideStringLiterals(): void
    {
        $src = <<<'PHP'
<?php
$code = "abstract class BaseE { abstract public string \$x { get; } } class ChildE extends BaseE {}";
eval($code);
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('$x { get; }', $out);
        self::assertSame([], $registry);
    }

    /** @covers issue #7313 — promoted constructor parameters with property hooks */
    public function testLowersPromotedConstructorParamPropertyHooks(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public function __construct(
        public string $name {
            get => strtoupper($this->name);
            set => $this->name = strtolower($value);
        },
    ) {}
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$name {', $out);
        self::assertStringContainsString('public string $name,', $out);
        self::assertStringNotContainsString('$name;', $out);
        self::assertStringContainsString('function __phpc_property_get_name', $out);
        self::assertStringContainsString('function __phpc_property_set_name', $out);
        self::assertSame('__phpc_property_get_name', $registry['c']['name']['get'] ?? null);
        self::assertSame('__phpc_property_set_name', $registry['c']['name']['set'] ?? null);
    }

    /** @covers issue #29242 — default after promoted ctor hook block is Zend ParseError */
    public function testPromotedConstructorParamHookDefaultAfterThrowsParseError(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public function __construct(
        public int $x {
            get => $this->x * 2;
            set {
                $this->x = $value + 1;
            }
        } = 1
    ) {}
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::PROMOTED_HOOK_DEFAULT_AFTER_PARSE_ERROR);
        (new PropertyHooks())->process($src, 'promoted_hook_default.php');
    }

    /** @covers issue #29242 — default before promoted ctor hook block remains valid */
    public function testPromotedConstructorParamHookDefaultBeforeStillLowers(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public function __construct(
        public int $x = 1 {
            get => $this->x * 2;
            set {
                $this->x = $value + 1;
            }
        }
    ) {}
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$x {', $out);
        self::assertStringContainsString('public int $x = 1', $out);
        self::assertStringNotContainsString('$x = 1;', $out);
        self::assertSame('__phpc_property_get_x', $registry['c']['x']['get'] ?? null);
        self::assertSame('__phpc_property_set_x', $registry['c']['x']['set'] ?? null);
    }

    /** @covers issue #7313 — promoted hooked param end-to-end via Runtime preprocess */
    public function testPromotedConstructorParamPropertyHooksSurviveRuntimePreprocess(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public function __construct(
        public string $name {
            get => strtoupper($this->name);
            set => $this->name = strtolower($value);
        },
    ) {}
}
$c = new C('AbC');
echo $c->name;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($src, 'promoted_property_hook.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('ABC', ob_get_clean());
    }

    /** @covers issue #7031 — same-name backing field must merge with hooked property decl */
    public function testMergesSameNameBackingFieldDeclaration(): void
    {
        $src = <<<'PHP'
<?php
class Evaled {
    public string $name {
        get => strtoupper($this->name ?? "");
        set => $this->name = strtolower($value);
    }
    private string $name = "x";
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('public string $name = "x";', $out);
        self::assertStringNotContainsString('private string $name', $out);
        self::assertSame('__phpc_property_set_name', $registry['evaled']['name']['set'] ?? null);
    }

    /** @covers issue #16936 — detached same-name backing field merges when sibling property sits between hook and backing */
    public function testDetachedSameNameBackingFieldMergesWithSiblingProperty(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public int $x {
        get => $this->x;
        set => $this->x = $value;
    }
    public string $y = 'a';
    private int $x = 1;
}
$c = new C();
echo 'compile-ok x=' . $c->x . ' y=' . $c->y . "\n";
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('public int $x = 1;', $out);
        self::assertStringContainsString("public string \$y = 'a';", $out);
        self::assertStringNotContainsString('private int $x', $out);
        self::assertSame('__phpc_property_set_x', $registry['c']['x']['set'] ?? null);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($src, 'property_hook_multi_property_redeclare.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("compile-ok x=1 y=a\n", ob_get_clean());
    }

    /** @covers issue #18171 — same-name backing declared before hooked property with unset block */
    public function testPriorSameNameBackingFieldMergesWithUnsetBlock(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    private string $x = 'a';
    public string $x {
        get => $this->x;
        unset { unset($this->x); }
    }
}
$c = new C();
unset($c->x);
echo 'isset=' . var_export(isset($c->x), true) . "\n";
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString("public string \$x = 'a';", $out);
        self::assertStringNotContainsString('private string $x', $out);
        self::assertSame('__phpc_property_unset_x', $registry['c']['x']['unset'] ?? null);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($src, 'property_hook_unset_prior_backing.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("isset=false\n", ob_get_clean());
    }

    /** @covers issue #10393 — true duplicate same-name field without hook backing use still fails compile */
    public function testDetachedSameNameBackingFieldIsDuplicateProperty(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public int $x {
        get => 1;
    }
    public string $y = 'a';
    private int $x = 1;
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('public int $x;', $out);
        self::assertStringContainsString('private int $x = 1;', $out);

        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redeclare C::$x');
        $runtime->parseAndCompile($src, 'property_hook_virtual_duplicate_name.php');
    }

    /** @covers issue #19172 — readonly + property hooks rejected at compile time (php-src #15439) */
    public function testReadonlyHookedPropertyFailsAtCompileTime(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public readonly int $x {
        get => $this->x;
        set { $this->x = $value; }
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('public readonly int $x;', $out);
        self::assertStringContainsString('__phpc_property_set_x', $out);
        self::assertSame('__phpc_property_set_x', $registry['c']['x']['set'] ?? null);

        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Hooked properties cannot be readonly');
        $runtime->parseAndCompile($src, 'readonly_hooked_property.php');
    }

    /** @covers issue #18170 — explicit virtual modifier stripped before php-parser; registry marks virtual */
    public function testExplicitVirtualModifierStrippedAndMarkedVirtual(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class Base {
    public virtual string $x {
        get => 'base';
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('virtual', $out);
        self::assertStringContainsString('public string $x;', $out);
        self::assertTrue($registry['base']['x']['virtual'] ?? false);
    }

    /** @covers issue #18170 — parent::$prop->get() lowered to parent hook method call */
    public function testRewriteParentPropertyHookRefGetCall(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class Base {
    public virtual string $x {
        get => 'base';
    }
}
class Child extends Base {
    public virtual string $x {
        get => parent::$x->get() . '-child';
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('parent::__phpc_property_get_x()', $out);
        self::assertStringNotContainsString('parent::$x->get()', $out);
    }

    /** @covers issue #21296 — parent::$prop::get()/::set() (documented PHP 8.4 form) */
    public function testRewriteParentPropertyHookColonColonGetSet(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class Base {
    public string $name {
        get => 'BASE';
        set { }
    }
}
class Child extends Base {
    public string $name {
        get => parent::$name::get() . '+C';
        set => parent::$name::set($value);
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('parent::__phpc_property_get_name()', $out);
        self::assertStringContainsString('parent::__phpc_property_set_name($value)', $out);
        self::assertStringNotContainsString('parent::$name::get()', $out);
        self::assertStringNotContainsString('parent::$name::set(', $out);
    }

    /** @covers issue #21296 — parent::$prop::get()/::set() colon form (php.net / zend_property_hooks.c) */
    public function testRewriteParentPropertyHookColonGetSetCall(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class Base {
    public string $name {
        get => 'BASE';
        set { }
    }
}
class Child extends Base {
    public string $name {
        get => parent::$name::get() . '+C';
        set {
            parent::$name::set($value);
        }
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('parent::__phpc_property_get_name()', $out);
        self::assertStringContainsString('parent::__phpc_property_set_name($value)', $out);
        self::assertStringNotContainsString('parent::$name::get()', $out);
        self::assertStringNotContainsString('parent::$name::set(', $out);
    }

    /** @covers issue #21296 — process() must not skip later hooked classes when rewritten bodies grow */
    public function testProcessRewritesAllHookedClassesWhenBufferGrows(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class Base {
    public string $name {
        get => 'BASE';
        set { }
    }
}
class Child extends Base {
    public string $name {
        get => parent::$name::get() . '+C';
        set { }
    }
}
class BaseStore {
    public string $v = '';
    public string $name {
        get => $this->v;
        set => $this->v = $value;
    }
}
class ChildStore extends BaseStore {
    public string $name {
        get => parent::$name::get();
        set => parent::$name::set(strtoupper($value));
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertArrayHasKey('childstore', $registry);
        self::assertStringContainsString(
            'parent::__phpc_property_set_name(strtoupper($value))',
            $out
        );
        self::assertStringNotContainsString('parent::$name::', $out);
    }

    /** @covers issue #16861 — virtual default + hook block rejected with Zend compile error on forward profile */
    public function testDefaultInitializerWithVirtualPropertyHooksRejectedOnForwardProfile(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public string $label = 'default' {
        get => 'virtual';
    }
}
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(
            PropertyHooks::virtualHookedDefaultCompileError('C', 'label')
        );
        PropertyHookSyntaxRejector::reject($src, 'virtual_default_initializer.php');
    }

    /** @covers issue #12574 — default initializer + hook block rejected on reference profile */
    public function testDefaultInitializerWithPropertyHooksRejectedOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('reference-profile parse error only when property hooks disabled');
        }
        $src = <<<'PHP'
<?php
class C {
    public string $label = 'default' {
        get => $this->label;
    }
}
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_ARROW);
        PropertyHookSyntaxRejector::reject($src, 'default_initializer.php');
    }

    /** @covers issue #16861 — backed default + hook block allowed on forward profile (#11594) */
    public function testDefaultInitializerWithBackedPropertyHooksAllowedOnForwardProfile(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
class C {
    public string $label = 'default' {
        get => $this->label;
    }
}
$c = new C();
echo $c->label;
PHP;
        PropertyHookSyntaxRejector::reject($src, 'backed_default_initializer.php');
        $runtime = new Runtime();
        $runtime->parseAndCompile($src, 'backed_default_initializer.php');
        $this->addToAssertionCount(1);
    }

    /** @covers issue #9729 — promoted ctor defaults must not match property-hook `{` scanner */
    public function testSkipsPromotedConstructorParamDefaultBeforeMethodBody(): void
    {
        $src = <<<'PHP'
<?php
class D {
    public function __construct(private(get) int $x = 1) {}
}
class E {
    public function __construct(public int $y = 2) {}
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('__construct(private(get) int $x = 1) {}', preg_replace('/\s+/', ' ', $out));
        self::assertStringContainsString('__construct(public int $y = 2) {}', preg_replace('/\s+/', ' ', $out));
        self::assertStringNotContainsString('__phpc_property_', $out);
    }

    /** @covers issue #16495 — parenthesized asymmetric set on promoted params accepted on 8.4 profile */
    public function testPromotedAsymmetricVisibilityParenthesizedFormCompilesOn84Profile(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('parenthesized asymmetric set requires 8.4 profile (#16495)');
        }
        $src = <<<'PHP'
<?php
class D {
    public function __construct(public (private(set)) int $x = 1) {}
}
PHP;
        $runtime = new Runtime();
        $script = $runtime->parseAndCompile($src, 'promoted_asymmetric_default.php');
        self::assertNotNull($script);
    }

    /** @covers bootstrap spine — method param defaults before typed body must not match hook scanner */
    public function testSkipsMethodParamDefaultBeforeTypedFunctionBody(): void
    {
        $src = <<<'PHP'
<?php
class VmString {
    public static function coerceStringBuiltinArg(
        $var,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string'
    ): string {
        return (string) $var;
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertSame($src, $out);
        self::assertStringNotContainsString('__phpc_property_', $out);
    }

    public function testVmStringSpineFileUnchangedByPropertyHooksPreprocessor(): void
    {
        $path = dirname(__DIR__, 3).'/ext/standard/VmString.php';
        $src = (string) file_get_contents($path);
        [$out] = (new PropertyHooks())->process($src, $path);
        self::assertSame($src, $out);
    }

    /** @covers bootstrap spine — comparison operators and foreach arrows must not match hook default scanner */
    public function testSkipsComparisonOperatorsAndForeachArrowsBeforeControlFlowBrace(): void
    {
        $src = <<<'PHP'
<?php
class C {
    private static function percentEncode(string $data, bool $formEncoding): string
    {
        $out = '';
        for ($i = 0; $i < 1; ++$i) {
            $ch = $data[$i];
            if ($ch === '-' || $ch === '_') {
                $out .= $ch;
            } elseif ($formEncoding && $ch === ' ') {
                $out .= '+';
            }
        }
        return $out;
    }

    private static function map(array $psr4Map): bool
    {
        foreach ($psr4Map as $prefix => $_base) {
            if (str_starts_with('Foo', $prefix)) {
                return true;
            }
        }
        return false;
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertSame($src, $out);
    }

    /** @covers bootstrap M4 — for-loop increment `$handler = $handler->parent) {` must not match hook scanner (lib/VM.php) */
    public function testSkipsForLoopIncrementAssignmentBeforeControlFlowBrace(): void
    {
        $src = <<<'PHP'
<?php
class C {
    private function walk(Frame $frame): ?Frame
    {
        for ($handler = $frame; null !== $handler; $handler = $handler->parent) {
            if ($handler->fiberState !== null) {
                break;
            }
        }
        return null;
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertSame($src, $out);
    }

    public function testVmSpineFileUnchangedByPropertyHooksPreprocessor(): void
    {
        $path = dirname(__DIR__, 3).'/lib/VM.php';
        $src = (string) file_get_contents($path);
        [$out] = (new PropertyHooks())->process($src, $path);
        self::assertSame($src, $out);
    }

    /** @covers bootstrap M2 spine — FFI nowdoc cdef must not match hook default scanner (ext/standard/VmDirNative.php) */
    public function testVmDirNativeSpineFileUnchangedByPropertyHooksPreprocessor(): void
    {
        $path = dirname(__DIR__, 3).'/ext/standard/VmDirNative.php';
        $src = (string) file_get_contents($path);
        [$out] = (new PropertyHooks())->process($src, $path);
        self::assertSame($src, $out);
    }

    public function testSkipsMethodBodyHeredocAssignmentBeforeControlFlowBrace(): void
    {
        $src = <<<'PHP'
<?php
class C {
    private static function ffi(): ?\FFI {
        $cdef = <<<'CDEF'
typedef struct {
    char d_name[256];
} dirent;
CDEF;
        foreach (['a'] as $lib) {
            return null;
        }
        return null;
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertSame($src, $out);
    }

    public function testStripsFinalModifierOnHookedProperty(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public final string $label {
        get => 'ok';
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('final string $label', $out);
        self::assertStringContainsString('public string $label;', $out);
        self::assertTrue($registry['c']['label']['finalProperty'] ?? false);
        self::assertSame('__phpc_property_get_label', $registry['c']['label']['get'] ?? null);
    }

    /** @covers issue #29424 — php-src GH-17916 final+abstract hooked property */
    public function testRejectsFinalAbstractHookedProperty(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $src = <<<'PHP'
<?php
abstract class A {
    final abstract public string $x { get; }
}
PHP;
            $this->expectException(CompileFatal::class);
            $this->expectExceptionMessage(PropertyHooks::FINAL_ABSTRACT_PROPERTY_COMPILE_ERROR);
            (new PropertyHooks())->process($src, 'final_abstract_prop.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #29424 — abstract-only / final-only hooked properties remain legal */
    public function testAllowsAbstractOnlyAndFinalOnlyHookedProperty(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $abstractOnly = <<<'PHP'
<?php
abstract class A {
    abstract public string $x { get; }
}
PHP;
            [, $registry] = (new PropertyHooks())->process($abstractOnly, 'abstract_only_prop.php');
            self::assertTrue($registry['a']['x']['abstract'] ?? false);
            self::assertFalse($registry['a']['x']['finalProperty'] ?? false);

            $finalOnly = <<<'PHP'
<?php
class C {
    public final string $x { get => 'ok'; }
}
PHP;
            [, $finalReg] = (new PropertyHooks())->process($finalOnly, 'final_only_prop.php');
            self::assertTrue($finalReg['c']['x']['finalProperty'] ?? false);
            self::assertFalse($finalReg['c']['x']['abstract'] ?? false);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #29425 — php-src zend_add_member_modifier final+private hooked property */
    public function testRejectsFinalPrivateHookedProperty(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $src = <<<'PHP'
<?php
class C {
    final private string $x {
        get => 'g';
        set {}
    }
}
PHP;
            $this->expectException(CompileFatal::class);
            $this->expectExceptionMessage(PropertyHooks::FINAL_PRIVATE_PROPERTY_COMPILE_ERROR);
            (new PropertyHooks())->process($src, 'final_private_prop.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #29425 — final + private(set) remains legal (not private read vis) */
    public function testAllowsFinalPublicPrivateSetHookedProperty(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $src = <<<'PHP'
<?php
class C {
    final public private(set) string $x {
        get => $this->x;
        set {}
    }
}
PHP;
            [, $registry] = (new PropertyHooks())->process($src, 'final_private_set_prop.php');
            self::assertTrue($registry['c']['x']['finalProperty'] ?? false);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #23069 — prior plain `final` must not mark following hooked property */
    public function testFinalPlainSiblingDoesNotBleedOntoHookedProperty(): void
    {
        $src = <<<'PHP'
<?php
class C {
    final public string $f = 'f';
    public string $hook {
        get => 'h';
        set { }
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('final public string $f', $out);
        self::assertStringNotContainsString('final public string $hook', $out);
        self::assertStringContainsString('public string $hook;', $out);
        self::assertFalse($registry['c']['hook']['finalProperty'] ?? false);
    }

    public function testLowersFinalSetHookModifier(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public string $x {
        final set => strtolower($value);
        get => $this->x ?? '';
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('function __phpc_property_set_x', $out);
        self::assertStringContainsString('strtolower($value)', $out);
        self::assertTrue($registry['c']['x']['finalSet'] ?? false);
        self::assertFalse($registry['c']['x']['finalGet'] ?? false);
    }

    /** @covers issue #17330 — get { } block records distinct read backing for VM dispatch */
    public function testGetBlockBodyRegistersDistinctReadBacking(): void
    {
        $src = <<<'PHP'
<?php
class C {
    private string $stored = 'g';
    public string $x {
        get {
            return $this->stored;
        }
        set {
            $this->stored = strtoupper($value);
        }
    }
}
PHP;
        [, $registry] = (new PropertyHooks())->process($src);
        self::assertSame('stored', $registry['c']['x']['getBacking'] ?? null);
        self::assertSame('stored', $registry['c']['x']['setBacking'] ?? null);
    }

    /** @covers issue #26328 — attributes before get/set lower onto synthetic hook methods */
    public function testLowersAttributesOntoHookMethods(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
#[Attribute]
class Marker {}
class Base {
    public string $x {
        get => "base";
        set {}
    }
}
class Child extends Base {
    public string $x {
        #[\Override]
        get => "child";
        #[Marker]
        set {}
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('get =>', $out);
        self::assertStringContainsString('#[\Override]', $out);
        self::assertStringContainsString('#[Marker]', $out);
        self::assertMatchesRegularExpression(
            '/#\[\\\\Override\]\s+public function __phpc_property_get_x/s',
            $out
        );
        self::assertMatchesRegularExpression(
            '/#\[Marker\]\s+public function __phpc_property_set_x/s',
            $out
        );
        self::assertSame('__phpc_property_get_x', $registry['child']['x']['get'] ?? null);
        self::assertSame('__phpc_property_set_x', $registry['child']['x']['set'] ?? null);
    }

    /** @covers issue #26328 — attributes before final &get */
    public function testLowersAttributesBeforeFinalByRefGet(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $src = <<<'PHP'
<?php
#[Attribute]
class HookAttr {}
class C {
    private array $a = [1];
    public array $x {
        #[HookAttr]
        final &get => $this->a;
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertMatchesRegularExpression(
            '/#\[HookAttr\]\s+public function &__phpc_property_get_x/s',
            $out
        );
    }

    /** @covers issue #29419 — untyped set($value) on typed hooked property is compile-fatal */
    public function testRejectsUntypedSetValueOnTypedHookedProperty(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $src = <<<'PHP'
<?php
class C {
    public string $x = 'a' {
        set($value) { $this->x = $value . '!'; }
    }
}
PHP;
            $this->expectException(CompileFatal::class);
            $this->expectExceptionMessage(PropertyHooks::setHookValueTypeCompatError('value', 'C', 'x'));
            PropertyHookSyntaxRejector::reject($src, 'hook_set_untyped.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #29419 — typed set(string $value) and short set { } remain legal */
    public function testAllowsTypedAndShortSetOnTypedHookedProperty(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $typed = <<<'PHP'
<?php
class C {
    public string $x = 'a' {
        set(string $value) { $this->x = $value . '!'; }
    }
}
PHP;
            [$out] = (new PropertyHooks())->process($typed, 'hook_set_typed.php');
            self::assertStringContainsString('function __phpc_property_set_x(string $value)', $out);

            $short = <<<'PHP'
<?php
class C {
    public string $x = 'a' {
        set { $this->x = $value . '!'; }
    }
}
PHP;
            [$outShort] = (new PropertyHooks())->process($short, 'hook_set_short.php');
            self::assertStringContainsString('function __phpc_property_set_x($value)', $outShort);

            $wide = <<<'PHP'
<?php
class C {
    public string $x = 'a' {
        set(string|Stringable $value) { $this->x = (string) $value; }
    }
}
PHP;
            [$outWide] = (new PropertyHooks())->process($wide, 'hook_set_wide.php');
            self::assertStringContainsString('function __phpc_property_set_x(string|Stringable $value)', $outWide);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #29419 — typed set on untyped property / incompatible set type */
    public function testRejectsTypedSetOnUntypedPropertyAndIncompatibleSetType(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $typedOnUntyped = <<<'PHP'
<?php
class C {
    public $x = 'a' {
        set(string $value) { $this->x = $value; }
    }
}
PHP;
            try {
                (new PropertyHooks())->process($typedOnUntyped, 'hook_set_typed_on_untyped.php');
                self::fail('Expected CompileFatal for typed set on untyped property');
            } catch (CompileFatal $e) {
                self::assertSame(PropertyHooks::setHookValueTypeCompatError('value', 'C', 'x'), $e->getMessage());
            }

            $incompat = <<<'PHP'
<?php
class C {
    public string $x = 'a' {
        set(int $value) { $this->x = (string) $value; }
    }
}
PHP;
            $this->expectException(CompileFatal::class);
            $this->expectExceptionMessage(PropertyHooks::setHookValueTypeCompatError('value', 'C', 'x'));
            (new PropertyHooks())->process($incompat, 'hook_set_incompat.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #29442 — set(&$value) is compile-fatal */
    public function testRejectsByRefSetHookParam(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $byRef = <<<'PHP'
<?php
class C {
    public $x {
        set(&$value) {
            $this->x = $value;
        }
    }
}
PHP;
            $this->expectException(CompileFatal::class);
            $this->expectExceptionMessage(PropertyHooks::setHookParamByRefCompileError('value', 'C', 'x'));
            (new PropertyHooks())->process($byRef, 'hook_set_by_ref.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #29442 — typed by-ref rejected; legal set($value) / set => unchanged */
    public function testRejectsTypedByRefSetHookParamAndAllowsLegalSet(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $typedByRef = <<<'PHP'
<?php
class C {
    public string $x = 'a' {
        set(string &$v) { $this->x = $v; }
    }
}
PHP;
            try {
                (new PropertyHooks())->process($typedByRef, 'hook_set_typed_by_ref.php');
                self::fail('Expected CompileFatal for typed by-ref set param');
            } catch (CompileFatal $e) {
                self::assertSame(PropertyHooks::setHookParamByRefCompileError('v', 'C', 'x'), $e->getMessage());
            }

            $block = <<<'PHP'
<?php
class C {
    public $x {
        set($value) { $this->x = $value; }
    }
}
PHP;
            [$out] = (new PropertyHooks())->process($block, 'hook_set_legal.php');
            self::assertStringContainsString('function __phpc_property_set_x($value)', $out);
            self::assertStringNotContainsString('&$value', $out);

            $arrow = <<<'PHP'
<?php
class C {
    public $x {
        set => $value;
    }
}
PHP;
            [$outArrow] = (new PropertyHooks())->process($arrow, 'hook_set_arrow_legal.php');
            self::assertStringContainsString('function __phpc_property_set_x($value)', $outArrow);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #29443 — set($value, $extra) is compile-fatal (Zend "exactly one parameters") */
    public function testRejectsExtraSetHookParam(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $extra = <<<'PHP'
<?php
class C {
    public $x {
        set($value, $extra) {
            $this->x = $value;
        }
    }
}
PHP;
            $this->expectException(CompileFatal::class);
            $this->expectExceptionMessage(PropertyHooks::setHookArityCompileError('C', 'x'));
            (new PropertyHooks())->process($extra, 'hook_set_extra_param.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #29443 — set() zero-param list fatal; shorthand / single-param / trailing comma OK */
    public function testRejectsEmptySetHookParamListAndAllowsLegalArity(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $empty = <<<'PHP'
<?php
class C {
    public $x {
        set() {
            $this->x = 1;
        }
    }
}
PHP;
            try {
                (new PropertyHooks())->process($empty, 'hook_set_empty_params.php');
                self::fail('Expected CompileFatal for empty set() param list');
            } catch (CompileFatal $e) {
                self::assertSame(PropertyHooks::setHookArityCompileError('C', 'x'), $e->getMessage());
            }

            $trailing = <<<'PHP'
<?php
class C {
    public $x {
        set($value,) {
            $this->x = $value;
        }
    }
}
PHP;
            [$outTrail] = (new PropertyHooks())->process($trailing, 'hook_set_trailing_comma.php');
            self::assertStringContainsString('function __phpc_property_set_x($value,)', $outTrail);

            $shorthand = <<<'PHP'
<?php
class C {
    public $x {
        set {
            $this->x = $value;
        }
    }
}
PHP;
            [$outShort] = (new PropertyHooks())->process($shorthand, 'hook_set_shorthand.php');
            self::assertStringContainsString('function __phpc_property_set_x($value)', $outShort);

            $arrow = <<<'PHP'
<?php
class C {
    public $x {
        set => $value;
    }
}
PHP;
            [$outArrow] = (new PropertyHooks())->process($arrow, 'hook_set_arrow_arity.php');
            self::assertStringContainsString('function __phpc_property_set_x($value)', $outArrow);

            $arrowExtra = <<<'PHP'
<?php
class C {
    public $x {
        set($a, $b) => $a;
    }
}
PHP;
            try {
                (new PropertyHooks())->process($arrowExtra, 'hook_set_arrow_extra.php');
                self::fail('Expected CompileFatal for set($a, $b) =>');
            } catch (CompileFatal $e) {
                self::assertSame(PropertyHooks::setHookArityCompileError('C', 'x'), $e->getMessage());
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

}
