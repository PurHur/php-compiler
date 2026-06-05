<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\SourcePreprocessor;

use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

final class PropertyHooksTest extends TestCase
{
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
        [$out] = (new PropertyHooks())->process($src);
        self::assertStringNotContainsString('$title {', $out);
        self::assertStringContainsString('public string $title;', $out);
        self::assertStringNotContainsString('__phpc_property_get_title', $out);
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

    public function testLowersStaticPropertyHooksAsStaticMethods(): void
    {
        $src = <<<'PHP'
<?php
class Box {
    public static string $label {
        get => 'static:' . self::$label;
        set => strtoupper($value);
    }
}
PHP;
        [$out, $registry] = (new PropertyHooks())->process($src);
        self::assertStringContainsString('public static string $label;', $out);
        self::assertStringContainsString('public static function __phpc_property_get_label', $out);
        self::assertStringContainsString('public static function __phpc_property_set_label', $out);
        self::assertStringContainsString("self::\$label = (strtoupper(\$value));", $out);
        self::assertTrue($registry['box']['label']['static'] ?? false);
    }
}
