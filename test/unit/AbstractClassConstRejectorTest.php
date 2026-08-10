<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\AbstractClassConstRejector;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #30011 */
final class AbstractClassConstRejectorTest extends TestCase
{
    public function testAbstractPublicConstWithoutValue(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(AbstractClassConstRejector::MESSAGE);

        AbstractClassConstRejector::reject(<<<'PHP'
<?php
abstract class A {
    abstract public const X;
}
PHP, 'test.php');
    }

    public function testAbstractTypedConstWithoutValue(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(AbstractClassConstRejector::MESSAGE);

        AbstractClassConstRejector::reject(<<<'PHP'
<?php
abstract class A {
    abstract public const int X;
}
PHP, 'test.php');
    }

    public function testInterfaceAbstractConstWithValue(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(AbstractClassConstRejector::MESSAGE);

        AbstractClassConstRejector::reject(<<<'PHP'
<?php
interface I {
    abstract public const X = 1;
}
PHP, 'test.php');
    }

    public function testPublicAbstractConstOrder(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(AbstractClassConstRejector::MESSAGE);

        AbstractClassConstRejector::reject(<<<'PHP'
<?php
abstract class A {
    public abstract const X = 1;
}
PHP, 'test.php');
    }

    public function testAbstractMethodAndPlainConstAllowed(): void
    {
        $code = <<<'PHP'
<?php
abstract class A {
    abstract public function f();
    public const X = 1;
}
PHP;
        self::assertSame($code, AbstractClassConstRejector::reject($code, 'test.php'));
    }

    public function testThroughRuntimeParse(): void
    {
        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(AbstractClassConstRejector::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
abstract class A {
    abstract public const X;
}
PHP, 'abstract_class_const.php');
    }
}
