<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** class_constants() VM builtin (issue #7309). */
final class ClassConstantsBuiltinTest extends TestCase
{
    public function testVmClassConstantsInterfaceAndEnum(): void
    {
        $code = <<<'PHP'
<?php
interface I7309 { const X = 1; }
enum E7309: string { case A = 'a'; case B = 'b'; }
var_export(class_constants('I7309'));
echo "\n";
var_export(class_constants(E7309::class));
echo "\n";
echo function_exists('class_constants') ? "exists_ok\n" : "exists_bad\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_constants.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "array (\n  'X' => 1,\n)\narray (\n  'A' => \\E7309::A,\n  'B' => \\E7309::B,\n)\nexists_ok\n",
            ob_get_clean()
        );
    }

    public function testVmClassConstantsMissingClass(): void
    {
        $code = <<<'PHP'
<?php
try {
    $unused = class_constants('Missing7309');
    echo "no-error\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_constants_missing.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("Class \"Missing7309\" not found\n", ob_get_clean());
    }

    public function testVmClassConstantsTraitRejected(): void
    {
        $code = <<<'PHP'
<?php
trait T7309 { const Z = 1; }
try {
    $unused = class_constants('T7309');
    echo "no-error\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_constants_trait.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("Cannot fetch constants from trait T7309\n", ob_get_clean());
    }
}
