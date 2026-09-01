<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Multi-class files can store DECLARE_* names on Temporary slots (#22642 / gen-0 spine).
 */
final class ClassLikeDeclareNameOperandTest extends TestCase
{
    public function testBcmathNumberSerializeFileCompiles(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/ext/bcmath/NumberSerialize.php';
        $this->assertFileExists($path);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompileFile($path);
        $this->assertNotNull($block);
    }

    public function testTwoClassesInOneFileVmRun(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
final class Alpha { public static function tag(): string { return 'a'; } }
final class Beta { public static function tag(): string { return 'b'; } }
echo Alpha::tag(), Beta::tag(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'two_classes.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block, false);
        $this->assertSame("ab\n", ob_get_clean());
    }
}
