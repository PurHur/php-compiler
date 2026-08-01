<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26502 */
final class EnumBuiltinMethodRedeclareCheckTest extends TestCase
{
    public function testUnitEnumRedeclareCasesFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redeclare E::cases()');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    public static function cases(): array {
        return [];
    }
}
PHP,
            'enum_redeclare_cases.php'
        );
    }

    public function testUnitEnumRedeclareCasesCaseInsensitive(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redeclare E::cases()');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    public static function CASES(): array {
        return [];
    }
}
PHP,
            'enum_redeclare_CASES.php'
        );
    }

    public function testBackedEnumRedeclareFromFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redeclare E::from()');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int {
    case A = 1;
    public static function from(int|string $v): static {
        return self::A;
    }
}
PHP,
            'enum_redeclare_from.php'
        );
    }

    public function testBackedEnumRedeclareTryFromFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redeclare E::tryfrom()');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int {
    case A = 1;
    public static function tryFrom(int|string $v): ?static {
        return null;
    }
}
PHP,
            'enum_redeclare_tryfrom.php'
        );
    }

    public function testUnitEnumMayDeclareFrom(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    public static function from($v) {
        return self::A;
    }
}
PHP,
            'enum_unit_from_ok.php'
        );
        $this->assertNotNull($block);
    }

    public function testEnumOtherMethodsStillAllowed(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    public static function label(): string {
        return 'a';
    }
}
PHP,
            'enum_other_method_ok.php'
        );
        $this->assertNotNull($block);
    }
}
