<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5055 */
final class EnumMagicMethodCheckTest extends TestCase
{
    public function testEnumToStringMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum E cannot include magic method __toString');
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E implements Stringable {
    case A;
    public function __toString(): string {
        return 'a';
    }
}
PHP,
            'enum_tostring.php'
        );
    }

    public function testBackedEnumStringableWithoutCustomToStringCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string implements Stringable {
    case A = 'x';
}
echo E::A;
PHP,
            'enum_stringable.php'
        );
        $this->assertNotNull($block);
    }
}
