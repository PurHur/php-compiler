<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #12971 */
final class ImplementsHierarchyCompileCheckTest extends TestCase
{
    public function testClassImplementsClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {}
class B implements A {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('B cannot implement A - it is not an interface');
        $runtime->parseAndCompile($code, 'implements_class.php');
    }

    public function testEnumImplementsClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {}
enum E implements C { case A; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('E cannot implement C - it is not an interface');
        $runtime->parseAndCompile($code, 'enum_implements_class.php');
    }

    public function testClassExtendsInterfaceFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {}
class C extends I {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C cannot extend interface I');
        $runtime->parseAndCompile($code, 'extends_interface.php');
    }

    /** @covers issue #26537 */
    public function testClassExtendsTraitFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {}
class C extends T {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C cannot extend trait T');
        $runtime->parseAndCompile($code, 'extends_trait.php');
    }

    /** @covers issue #26537 */
    public function testTraitUseCompositionStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public function hi() { echo "hi\n"; } }
class C { use T; }
(new C)->hi();
PHP;
        $block = $runtime->parseAndCompile($code, 'use_trait.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("hi\n", ob_get_clean());
    }

    public function testInterfaceExtendsClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {}
interface I extends C {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('I cannot implement C - it is not an interface');
        $runtime->parseAndCompile($code, 'interface_extends_class.php');
    }

    public function testValidHierarchyCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {}
class C implements I {}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** @covers issue #12972 */
    public function testClassDuplicateImplementsFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {}
class C implements I, I {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C cannot implement previously implemented interface I');
        $runtime->parseAndCompile($code, 'duplicate_implements.php');
    }

    /** @covers issue #12972 */
    public function testEnumDuplicateImplementsFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {}
enum E implements I, I { case A; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum E cannot implement previously implemented interface I');
        $runtime->parseAndCompile($code, 'enum_duplicate_implements.php');
    }

    /** @covers issue #12972 */
    public function testInterfaceDuplicateExtendsFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B extends A, A {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Interface B cannot implement previously implemented interface A');
        $runtime->parseAndCompile($code, 'interface_duplicate_extends.php');
    }

    /** @covers issue #9722 */
    public function testClassImplementsClassAfterRuntimeStatementsFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {}
try {
    echo "ok\n";
} catch (Throwable $e) {
}
class B implements A {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('B cannot implement A - it is not an interface');
        $runtime->parseAndCompile($code, 'nested.php');
    }

    /** @covers issue #25869 */
    public function testClassImplementsThrowableFailsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class X implements Throwable {
  public function getMessage(): string { return ""; }
  public function getCode() { return 0; }
  public function getFile(): string { return ""; }
  public function getLine(): int { return 0; }
  public function getTrace(): array { return []; }
  public function getTraceAsString(): string { return ""; }
  public function getPrevious(): ?Throwable { return null; }
  public function __toString(): string { return ""; }
}
PHP;
        $block = $runtime->parseAndCompile($code, 'implements_throwable.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  Class X cannot implement interface Throwable, extend Exception or Error instead'
        );
        $runtime->run($block, false);
    }

    /** @covers issue #25869 */
    public function testEmptyClassImplementsThrowableFailsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Y implements Throwable {}
PHP;
        $block = $runtime->parseAndCompile($code, 'implements_throwable_empty.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  Class Y cannot implement interface Throwable, extend Exception or Error instead'
        );
        $runtime->run($block, false);
    }

    /** @covers issue #25869 */
    public function testEnumImplementsThrowableFailsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E implements Throwable { case A; }
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_implements_throwable.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  Enum E cannot implement interface Throwable'
        );
        $runtime->run($block, false);
    }

    /** @covers issue #25869 */
    public function testClassExtendsExceptionImplementsThrowableAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Z extends Exception implements Throwable {}
echo class_exists("Z", false) ? "ok\n" : "missing\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'extends_exception_implements_throwable.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame("ok\n", $out);
    }

    /** @covers issue #25869 */
    public function testClassImplementsThrowableCompilesThenFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class X implements Throwable {
  public function getMessage(): string { return ""; }
  public function getCode() { return 0; }
  public function getFile(): string { return ""; }
  public function getLine(): int { return 0; }
  public function getTrace(): array { return []; }
  public function getTraceAsString(): string { return ""; }
  public function getPrevious(): ?Throwable { return null; }
  public function __toString(): string { return ""; }
}
PHP;
        $block = $runtime->parseAndCompile($code, 'implements_throwable.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  Class X cannot implement interface Throwable, extend Exception or Error instead'
        );
        $runtime->run($block, false);
    }

    /** @covers issue #25869 */
    public function testEmptyClassImplementsThrowableFatalsWithBanNotAbstractList(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Y implements Throwable {}
PHP;
        $block = $runtime->parseAndCompile($code, 'implements_throwable_empty.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  Class Y cannot implement interface Throwable, extend Exception or Error instead'
        );
        $runtime->run($block, false);
    }

    /** @covers issue #25869 */
    public function testEnumImplementsThrowableFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E implements Throwable { case A; }
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_implements_throwable.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PHP Fatal error:  Enum E cannot implement interface Throwable');
        $runtime->run($block, false);
    }

    /** @covers issue #26538 */
    public function testEnumImplementsSerializableFailsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E implements Serializable {
  case A;
  public function serialize() { return ""; }
  public function unserialize($d) {}
}
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_implements_serializable.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  Enum E cannot implement the Serializable interface'
        );
        $runtime->run($block, false);
    }

    /** @covers issue #26538 */
    public function testEnumImplementsSerializablePrintsPrecedingOutput(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo "before\n";
enum E implements Serializable {
  case A;
  public function serialize() { return ""; }
  public function unserialize($d) {}
}
echo "reach\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_serializable_before.php');
        $this->assertNotNull($block);
        ob_start();
        try {
            $runtime->run($block, false);
            $this->fail('expected Serializable ban fatal');
        } catch (\LogicException $e) {
            $out = ob_get_clean();
            $this->assertSame("before\n", $out);
            $this->assertStringContainsString(
                'Enum E cannot implement the Serializable interface',
                $e->getMessage()
            );
        }
    }

    /** @covers issue #25869 */
    public function testExtendsExceptionImplementsThrowableAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Z extends Exception implements Throwable {}
echo class_exists('Z', false) ? "ok\n" : "missing\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'extends_exception_implements_throwable.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** @covers issue #13325, #18781 */
    public function testClassImplementsDateTimeInterfaceCompilesThenFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class UserDateTime implements DateTimeInterface {}
PHP;
        $block = $runtime->parseAndCompile($code, 'datetimeinterface.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("PHP Fatal error:  DateTimeInterface can't be implemented by user classes");
        $runtime->run($block, false);
    }

    /** @covers issue #13325, #18781 */
    public function testEnumImplementsDateTimeInterfaceCompilesThenFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E implements DateTimeInterface { case A; }
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_datetimeinterface.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("PHP Fatal error:  DateTimeInterface can't be implemented by user classes");
        $runtime->run($block, false);
    }

    /** @covers issue #18781 */
    public function testClassImplementsInternalIteratorFailsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class UserInternalIterator implements InternalIterator {}
PHP;
        $block = $runtime->parseAndCompile($code, 'internaliterator.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  UserInternalIterator cannot implement InternalIterator - it is not an interface'
        );
        $runtime->run($block);
    }

    /** @covers issue #13326 */
    public function testClassImplementsTraversableDirectlyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class DirectTraversable implements Traversable {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Class DirectTraversable must implement interface Traversable as part of either Iterator or IteratorAggregate'
        );
        $runtime->parseAndCompile($code, 'traversable_direct.php');
    }

    /** @covers issue #13326 */
    public function testEnumImplementsTraversableDirectlyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E implements Traversable { case A; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Enum E must implement interface Traversable as part of either Iterator or IteratorAggregate'
        );
        $runtime->parseAndCompile($code, 'enum_traversable_direct.php');
    }

    /** @covers issue #13326 */
    public function testClassImplementsIteratorAndTraversableCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C implements Iterator, Traversable {
    public function current() { return null; }
    public function key() { return null; }
    public function next() {}
    public function rewind() {}
    public function valid() { return false; }
}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'iterator_traversable.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** @covers issue #18781 */
    public function testClassImplementsClosureFailsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C implements Closure {
    public function __invoke(): void {}
}
PHP;
        $block = $runtime->parseAndCompile($code, 'implements_closure.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PHP Fatal error:  C cannot implement Closure - it is not an interface');
        $runtime->run($block);
    }

    /** @covers issue #18781 */
    public function testClassImplementsGeneratorFailsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class G implements Generator {}
PHP;
        $block = $runtime->parseAndCompile($code, 'implements_generator.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PHP Fatal error:  G cannot implement Generator - it is not an interface');
        $runtime->run($block);
    }

    /** @covers issue #18781 */
    public function testClassImplementsStdClassFailsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class S implements stdClass {
    public int $x = 1;
}
PHP;
        $block = $runtime->parseAndCompile($code, 'implements_stdclass.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PHP Fatal error:  S cannot implement stdClass - it is not an interface');
        $runtime->run($block);
    }

    /** @covers issue #15447 */
    public function testClassImplementsUnitEnumFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C implements UnitEnum {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-enum class C cannot implement interface UnitEnum');
        $runtime->parseAndCompile($code, 'implements_unitenum.php');
    }

    /** @covers issue #15447 */
    public function testClassImplementsBackedEnumFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C implements BackedEnum {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-enum class C cannot implement interface BackedEnum');
        $runtime->parseAndCompile($code, 'implements_backedenum.php');
    }

    /** @covers issue #15447 */
    public function testBackedEnumWithoutExplicitUnitEnumStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
echo E::A->value, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'backed_enum_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    /** @covers issue #25946 */
    public function testBackedEnumImplementsBackedEnumFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum S: string implements BackedEnum { case A = "a"; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum S cannot implement previously implemented interface BackedEnum');
        $runtime->parseAndCompile($code, 'enum_implements_backedenum.php');
    }

    /** @covers issue #25946 */
    public function testUnitEnumImplementsUnitEnumFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum U implements UnitEnum { case A; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Enum U cannot implement previously implemented interface UnitEnum');
        $runtime->parseAndCompile($code, 'enum_implements_unitenum.php');
    }

    /** @covers issue #25946 */
    public function testUnitEnumImplementsBackedEnumFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum U implements BackedEnum { case A; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-backed enum U cannot implement interface BackedEnum');
        $runtime->parseAndCompile($code, 'unit_implements_backedenum.php');
    }

    /** @covers issue #25946 */
    public function testEnumImplementsOtherInterfaceStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {}
enum E implements I { case A; }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_implements_other.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
