<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** class_implements() VM builtin (issue #3099, #3748). */
final class ClassImplementsBuiltinTest extends TestCase
{
    public function testVmClassImplementsDirectAndInherited(): void
    {
        $code = <<<'PHP'
<?php
interface A {}
interface B extends A {}
class C implements B {}
$map = class_implements(C::class);
echo count($map), "\n";
echo isset($map['B']) ? '1' : '0';
echo isset($map['A']) ? '1' : '0';
echo class_implements('Missing') ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_implements.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("2\n110", ob_get_clean());
    }

    public function testVmClassImplementsOnObject(): void
    {
        $code = <<<'PHP'
<?php
interface I {}
class Box implements I {}
$o = new Box();
$map = class_implements($o);
echo isset($map['I']) ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_implements_obj.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('1', ob_get_clean());
    }

    public function testVmClassImplementsAutoloadFlagFalse(): void
    {
        $code = <<<'PHP'
<?php
interface I {}
class C implements I {}
$map = class_implements(new C, false);
echo isset($map['I']) ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_implements_autoload.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('1', ob_get_clean());
    }
}
