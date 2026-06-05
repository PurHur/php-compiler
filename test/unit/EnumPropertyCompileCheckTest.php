<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\EnumPropertyCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6005 */
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
