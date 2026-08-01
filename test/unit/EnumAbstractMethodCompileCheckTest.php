<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\AbstractEnumSourceRewriter;
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

    /** @covers issue #26519 — `abstract enum` is not Zend syntax (inverts #6887 / #3737) */
    public function testAbstractEnumWithAbstractMethodIsParseFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract enum E: int {
    case A = 1;
    abstract public function label(): string;
}
echo "compiled\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AbstractEnumSourceRewriter::MESSAGE);
        $runtime->parseAndCompile($code, 'abstract_enum_method.php');
    }

    public function testConcreteEnumImplementsAbstractEnumMethodIsParseFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract enum E: int {
    case A = 1;
    abstract public function label(): string;
}
enum F: int implements E {
    case A = 1;
    public function label(): string { return 'A'; }
}
echo F::A->label(), "\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(AbstractEnumSourceRewriter::MESSAGE);
        $runtime->parseAndCompile($code, 'abstract_enum_implements.php');
    }

    public function testEnumMissingInterfaceMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface Greeter {
    public function greet(): void;
}
enum Status implements Greeter {
    case Open;
}
echo "compiled\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum Status must implement 1 abstract private method (Greeter::greet)');
        $runtime->parseAndCompile($code, 'enum_interface_missing.php');
    }

    public function testEnumWithSatisfiedInterfaceMethodCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface Greeter {
    public function greet(): string;
}
enum Status implements Greeter {
    case Open;
    public function greet(): string {
        return 'hi';
    }
}
echo Status::Open->greet(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_interface_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("hi\n", ob_get_clean());
    }
}
