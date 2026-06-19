<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** class_uses() VM builtin (issue #3119, #3748). */
final class ClassUsesBuiltinTest extends TestCase
{
    public function testVmClassUsesTraitMap(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function m(): int { return 1; } }
class C { use T; }
$u = class_uses(C::class);
echo count($u), "\n";
echo $u['T'] === 'T' ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_uses.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\n1", ob_get_clean());
    }

    public function testVmClassUsesAutoloadFlagFalse(): void
    {
        $code = <<<'PHP'
<?php
trait T {}
class C { use T; }
$u = class_uses(new C, false);
echo isset($u['T']) ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_uses_autoload.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('1', ob_get_clean());
    }

    public function testVmClassUsesRecursiveNestedTraits(): void
    {
        $code = <<<'PHP'
<?php
trait A {}
trait B { use A; }
class C { use B; }
$direct = class_uses(C::class);
$recursive = class_uses_recursive(C::class);
echo isset($direct['B']) && !isset($direct['A']) ? '1' : '0';
echo isset($recursive['A']) ? '1' : '0';
echo isset($recursive['B']) ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_uses_recursive.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('111', ob_get_clean());
    }

    public function testVmClassUsesEnumCaseEmptyArray(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; case B = 'b'; }
$u1 = class_uses(E::A);
$u2 = class_uses(E::B);
echo is_array($u1) && 0 === count($u1) ? '1' : '0';
echo is_array($u2) && 0 === count($u2) ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_uses_enum_case.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('11', ob_get_clean());
    }

    public function testVmClassUsesEnumCaseTraitMap(): void
    {
        $code = <<<'PHP'
<?php
trait T {}
enum E { case A; use T; }
$byClass = class_uses(E::class);
$byCase = class_uses(E::A);
echo isset($byClass['T']) ? '1' : '0';
echo isset($byCase['T']) ? '1' : '0';
echo $byClass === $byCase ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_uses_enum_case_traits.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('111', ob_get_clean());
    }
}
