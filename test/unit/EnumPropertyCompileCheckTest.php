<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\EnumPropertyCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6005, #26558 */
final class EnumPropertyCompileCheckTest extends TestCase
{
    public function testEnumInstancePropertyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumPropertyCompileCheck::messageFor('E'));
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string {
    case A = 'a';
    public string $x = 'y';
}
PHP,
            'enum_no_properties.php'
        );
    }

    public function testEnumConstructorPromotionFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumPropertyCompileCheck::messageFor('E'));
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    public function __construct(public string $x = 'y') {}
}
PHP,
            'enum_ctor_prom.php'
        );
    }

    public function testEnumTraitWithInstancePropertyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumPropertyCompileCheck::messageFor('E'));
        $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public $x = 1; }
enum E { use T; case A; }
PHP,
            'enum_trait_prop.php'
        );
    }

    public function testEnumTraitWithStaticPropertyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumPropertyCompileCheck::messageFor('E'));
        $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public static $x = 1; }
enum E { use T; case A; }
PHP,
            'enum_trait_static_prop.php'
        );
    }

    public function testEnumNestedTraitWithPropertyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumPropertyCompileCheck::messageFor('E'));
        $runtime->parseAndCompile(<<<'PHP'
<?php
trait Inner { public $x = 1; }
trait Outer { use Inner; }
enum E { use Outer; case A; }
PHP,
            'enum_nested_trait_prop.php'
        );
    }

    public function testEnumTraitWithPromotedPropertyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumPropertyCompileCheck::messageFor('E'));
        $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public function __construct(public int $x = 1) {} }
enum E { use T; case A; }
PHP,
            'enum_trait_promoted_prop.php'
        );
    }

    public function testEnumMethodOnlyTraitStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public function hi(): int { return 1; } }
enum E { use T; case A; }
PHP,
            'enum_trait_method_ok.php'
        );
        $this->assertNotNull($block);
    }

    public function testEnumConstOnlyTraitStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public const X = 1; }
enum E { use T; case A; }
PHP,
            'enum_trait_const_ok.php'
        );
        $this->assertNotNull($block);
    }

    public function testEnumMethodsAndConstantsStillCompile(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int {
    case A = 1;
    public const X = 2;
    public function f(): int { return self::X; }
}
PHP,
            'enum_ok.php'
        );
        $this->assertNotNull($block);
    }
}
