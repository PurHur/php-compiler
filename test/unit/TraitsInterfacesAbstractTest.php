<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #144 */
final class TraitsInterfacesAbstractTest extends TestCase
{
    public function testMissingInterfaceMethodFailsAtClassDeclaration(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public function m(): void; }
class C implements I {}
echo "ok\n";
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('abstract method');
        $runtime->run($runtime->parseAndCompile($code, 'iface_missing.php'));
    }

    public function testAbstractClassInstantiationFails(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A { public function f(): int { return 1; } }
new A();
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot instantiate abstract class A');
        $runtime->run($runtime->parseAndCompile($code, 'abstract_new.php'));
    }

    public function testTraitConflictFailsAtClassDeclaration(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T1 { public function f(): int { return 1; } }
trait T2 { public function f(): int { return 2; } }
class C { use T1, T2; }
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('collision with');
        $runtime->run($runtime->parseAndCompile($code, 'trait_conflict.php'));
    }

    public function testValidInterfaceImplementationRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public function m(): void; }
class C implements I { public function m(): void {} }
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'iface_ok.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }
}
