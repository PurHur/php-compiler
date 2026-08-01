<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #23110 — private(set) implicit-final must not block in-class writes
 */
final class PrivateSetInClassWriteTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }

    public function testInClassWriteSucceedsAndExternalDenyUsesPrivateSetWording(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class U {
    public private(set) int $n = 0;
    public function bump(): void { $this->n = $this->n + 1; }
}
$u = new U();
$u->bump();
echo $u->n, "\n";
class T {
    public private(set) string $x = "a";
}
$t = new T();
try { $t->x = "z"; echo "x_ok\n"; }
catch (Error $e) { echo "x:", $e->getMessage(), "\n"; }
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_23110_vm.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "1\nx:Cannot modify private(set) property T::\$x from global scope\n",
            ob_get_clean()
        );
    }

    public function testChildCannotWriteInheritedPrivateSet(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class U { public private(set) int $n = 0; }
class Child extends U {
    public function bad(): void { $this->n = 9; }
}
try { (new Child())->bad(); echo "child_ok\n"; }
catch (Error $e) { echo "child:", $e->getMessage(), "\n"; }
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_23110_child.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "child:Cannot modify private(set) property U::\$n from scope Child\n",
            ob_get_clean()
        );
    }

    public function testChildCannotRedeclarePrivateSetProperty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class U { public private(set) int $n = 0; }
class Child extends U { public private(set) int $n = 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final property U::$n');
        $runtime->parseAndCompile($code, 'issue_23110_redeclare.php');
    }
}
