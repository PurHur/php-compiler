<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * sort() on enum case arrays — transitive compare parity (#5546).
 */
final class SortEnumCasesBuiltinTest extends TestCase
{
    public function testBackedEnumSortPreservesObjectsAndOrdersByBacking(): void
    {
        ob_start();
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum EInt: int { case A = 1; case B = 2; case C = 3; }
$a = [EInt::C, EInt::A, EInt::B];
sort($a);
echo implode(',', array_map(fn($v) => $v->name.':'.(int) ($v instanceof EInt), $a));
PHP, 'sort_backed_enum.php'));
        $output = ob_get_clean();

        $this->assertSame('A:1,B:1,C:1', $output);
    }

    public function testUnitEnumSortPreservesObjects(): void
    {
        ob_start();
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum EUnit { case A; case B; }
$b = [EUnit::B, EUnit::A];
sort($b);
echo implode(',', array_map(fn($v) => $v->name.':'.(int) ($v instanceof EUnit), $b));
PHP, 'sort_unit_enum.php'));
        $output = ob_get_clean();

        $this->assertSame('B:1,A:1', $output);
        $this->assertStringNotContainsString(':0', $output);
    }

    public function testRsortPreservesBackedEnumCasesDescending(): void
    {
        ob_start();
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum EInt: int { case A = 1; case B = 2; case C = 3; }
$a = [EInt::C, EInt::A, EInt::B];
rsort($a);
echo $a[0]->name, ',', $a[1]->name, ',', $a[2]->name;
PHP, 'rsort_backed_enum.php'));
        $output = ob_get_clean();

        $this->assertSame('C,B,A', $output);
    }

    public function testArsortPreservesBackedEnumCasesDescending(): void
    {
        ob_start();
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum EInt: int { case A = 1; case B = 2; }
$a = ['x' => EInt::A, 'y' => EInt::B];
arsort($a);
$names = [];
foreach ($a as $v) {
    $names[] = $v->name;
}
echo implode(',', $names);
PHP, 'arsort_backed_enum.php'));
        $output = ob_get_clean();

        $this->assertSame('B,A', $output);
    }

    /** @covers issue #5691 */
    public function testStringBackedEnumSortUsesObjectHandleOrder(): void
    {
        ob_start();
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum EStr: string { case A = 'b'; case B = 'a'; }
$a = [EStr::B, EStr::A];
sort($a);
echo $a[0]->name, ',', $a[1]->name;
PHP, 'sort_string_backed_enum.php'));
        $output = ob_get_clean();

        $this->assertSame('A,B', $output);
    }

    /** @covers issue #5691 */
    public function testIntBackedEnumSortUsesDeclarationHandleNotBackingValue(): void
    {
        ob_start();
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum EOrder: int { case C = 3; case A = 1; case B = 2; }
$a = [EOrder::B, EOrder::C, EOrder::A];
sort($a);
echo implode(',', array_map(fn($v) => $v->name, $a));
PHP, 'sort_enum_declaration_order.php'));
        $output = ob_get_clean();

        $this->assertSame('C,A,B', $output);
    }
}
