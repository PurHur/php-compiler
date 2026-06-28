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

    /** @covers issue #13325 */
    public function testClassImplementsDateTimeInterfaceFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class UserDateTime implements DateTimeInterface {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("DateTimeInterface can't be implemented by user classes");
        $runtime->parseAndCompile($code, 'datetimeinterface.php');
    }

    /** @covers issue #13325 */
    public function testEnumImplementsDateTimeInterfaceFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E implements DateTimeInterface { case A; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("DateTimeInterface can't be implemented by user classes");
        $runtime->parseAndCompile($code, 'enum_datetimeinterface.php');
    }

    /** @covers issue #13327 */
    public function testClassImplementsInternalIteratorFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class UserInternalIterator implements InternalIterator {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'UserInternalIterator cannot implement InternalIterator - it is not an interface'
        );
        $runtime->parseAndCompile($code, 'internaliterator.php');
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
}
