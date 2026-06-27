<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\SourcePreprocessor;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CompilerVersion;
use PHPCompiler\PropertyHookProfileSkipTrait;
use PHPCompiler\Runtime;
use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

final class PropertyHooksTest extends TestCase
{
    use PropertyHookProfileSkipTrait;

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

    public function testLowersStaticPropertyHooks(): void
    {
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
        [$out, $registry] = (new PropertyHooks())->process($src, 'static_hooks.php');
        self::assertStringContainsString('public static string $label;', $out);
        self::assertStringContainsString('public static function __phpc_property_get_label(): string', $out);
        self::assertStringContainsString('public static function __phpc_property_set_label(string $value): void', $out);
        self::assertTrue($registry['box']['label']['static'] ?? false);
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
        self::assertStringContainsString('/*phpc-asymmetric-set:protected*/ public string $x;', $out);
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
        self::assertStringContainsString('/*phpc-asymmetric-set:private*/ public string $x;', $out);
        self::assertStringContainsString('function __phpc_property_set_x($value)', $out);
    }

    /** @covers issue #7148 — brace hook `private set;` modifier before set keyword */
    public function testLowersBraceHookPrivateSetModifierOnConcreteClass(): void
    {
        $src = <<<'PHP'
<?php
class User {
    public string $email { get; private set; }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$email {', $out);
        self::assertStringContainsString('/*phpc-asymmetric-set:private*/ public string $email;', $out);
        self::assertArrayNotHasKey('requiresGet', $registry['user']['email'] ?? []);
        self::assertArrayNotHasKey('requiresSet', $registry['user']['email'] ?? []);
    }

    /** @covers issue #12203 — `private(set)` decl + get-only hook is a Zend parse error */
    public function testRejectsAsymmetricDeclSetWithGetOnlyHook(): void
    {
        $src = <<<'PHP'
<?php
class C {
    private(set) string $x {
        get => 'hi';
    }
}
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(PropertyHooks::ASYMMETRIC_DECL_SET_REQUIRES_SET_HOOK_MESSAGE);

        (new PropertyHooks())->process($src, 'asymmetric_get_only.php');
    }

    /** @covers issue #12203 — implicit `get; private set;` backing still allowed */
    public function testAllowsAsymmetricDeclSetWithImplicitBackingHooks(): void
    {
        $src = <<<'PHP'
<?php
class C {
    private(set) string $x {
        get;
        private set;
    }
}
PHP;
        [$out] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$x {', $out);
        self::assertStringContainsString('private(set) string $x;', $out);
    }

    /** @covers issue #9872 — PHP 8.4 `private(set);` inside property hook block */
    public function testLowersHookBlockPrivateSetParenModifier(): void
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
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$x {', $out);
        self::assertStringNotContainsString('private(set)', $out);
        self::assertStringContainsString('/*phpc-asymmetric-set:private*/ public string $x;', $out);
        self::assertStringContainsString('function __phpc_property_get_x', $out);
        self::assertArrayNotHasKey('set', $registry['c']['x'] ?? []);
        self::assertTrue($registry['c']['x']['virtual'] ?? false);
    }

    public function testLowersBraceHookPrivateSetArrowHook(): void
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
        [$out] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('/*phpc-asymmetric-set:private*/ public string $x;', $out);
        self::assertStringContainsString('function __phpc_property_set_x', $out);
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

    /** @covers issue #7313 — promoted hooked param end-to-end via Runtime preprocess */
    public function testPromotedConstructorParamPropertyHooksSurviveRuntimePreprocess(): void
    {
        $this->skipUnlessPropertyHooks();
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

    /** @covers issue #10393 — detached same-name backing field is duplicate property, not merged */
    public function testDetachedSameNameBackingFieldIsDuplicateProperty(): void
    {
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
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('public int $x;', $out);
        self::assertStringContainsString('private int $x = 1;', $out);
        self::assertSame('__phpc_property_set_x', $registry['c']['x']['set'] ?? null);

        $this->skipUnlessPropertyHooks();
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redeclare C::$x');
        $runtime->parseAndCompile($src, 'property_hook_multi_property_redeclare.php');
    }

    /** @covers issue #9835 — readonly hooked property compiles; runtime enforces post-construct writes */
    public function testReadonlyHookedPropertyLowers(): void
    {
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
    }

    /** @covers issue #11594 — inline default initializer before property hook block (PHP 8.4) */
    public function testDefaultInitializerWithPropertyHooksLowers(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public string $label = 'default' {
        get => $this->label;
    }
}
trait T {
    public string $label = 'from-trait' {
        get => $this->label;
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString("public string \$label = 'default';", $out);
        self::assertStringContainsString("public string \$label = 'from-trait';", $out);
        self::assertSame('__phpc_property_get_label', $registry['c']['label']['get'] ?? null);
        self::assertSame('__phpc_property_get_label', $registry['t']['label']['get'] ?? null);
    }

    /** @covers issue #11594 — end-to-end via Runtime preprocess */
    public function testDefaultInitializerWithPropertyHooksSurvivesRuntimePreprocess(): void
    {
        $this->skipUnlessPropertyHooks();
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
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($src, 'property_hook_default_initializer.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('default', ob_get_clean());
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

    /** @covers issue #9729 — promoted asymmetric visibility with defaults end-to-end */
    public function testPromotedAsymmetricVisibilityWithDefaultSurvivesRuntimePreprocess(): void
    {
        if (!CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility disabled on reference profile (#12508)');
        }
        $src = <<<'PHP'
<?php
class D {
    public function __construct(private(set) int $x = 1) {}
}
$d = new D();
echo $d->x, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($src, 'promoted_asymmetric_default.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n", ob_get_clean());
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

}
