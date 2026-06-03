<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3737 */
final class AbstractEnumTest extends TestCase
{
    public function testAbstractEnumCaseNameOnVm(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract enum E { case A; }
echo E::A->name;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'abstract_enum.php'));
        self::assertSame('A', ob_get_clean());
    }

    public function testAbstractEnumInstantiationIsCompileTimeFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract enum E { case A; }
new E();
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot instantiate enum E');
        $runtime->parseAndCompile($code, 'abstract_enum_instantiate.php');
    }

    public function testDynamicAbstractEnumInstantiationIsRuntimeFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract enum E { case A; }
$c = 'E';
new $c();
PHP;
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot instantiate enum E');
        $runtime->run($runtime->parseAndCompile($code, 'abstract_enum_dynamic.php'));
    }

    public function testUnitEnumInstantiationThrowsError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E { case A; }
new E();
PHP;
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot instantiate enum E');
        $runtime->run($runtime->parseAndCompile($code, 'unit_enum_instantiate.php'));
    }

    public function testDynamicBackedEnumInstantiationThrowsError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum F: int { case B = 1; }
$class = F::class;
new $class();
PHP;
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot instantiate enum F');
        $runtime->run($runtime->parseAndCompile($code, 'backed_enum_dynamic.php'));
    }
}
