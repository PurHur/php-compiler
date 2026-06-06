<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6618 */
final class EnumAbstractMethodCompileCheckTest extends TestCase
{
    public function testUnimplementedAbstractEnumMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E {
    abstract public function f(): void;
    case A;
}
echo "compiled\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum E must implement 1 abstract private method (E::f)');
        $runtime->parseAndCompile($code, 'enum_abstract.php');
    }

    public function testTraitAbstractRequiresEnumConcreteImplementation(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    abstract public function f(): void;
}
enum E {
    case A;
    use T;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum E must implement 1 abstract private method (E::f)');
        $runtime->parseAndCompile($code, 'enum_trait_abstract.php');
    }

    public function testBackedEnumTraitAbstractRequiresConcreteImplementation(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    abstract public function f(): string;
}
enum E: string {
    case A = 'a';
    use T;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum E must implement 1 abstract private method (E::f)');
        $runtime->parseAndCompile($code, 'enum_trait_abstract_backed.php');
    }

    public function testConcreteEnumMethodCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E {
    case A;
    public function f(): void {
        echo "ok\n";
    }
}
E::A->f();
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_concrete.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testTraitAbstractWithEnumConcreteCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    abstract public function f(): void;
}
enum E {
    case A;
    use T;
    public function f(): void {
        echo "ok\n";
    }
}
E::A->f();
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_trait_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
