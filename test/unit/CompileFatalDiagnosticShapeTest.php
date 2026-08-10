<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Zend-shaped "PHP Fatal error:" stderr prefix for compile fatals (#27718, #29769).
 */
final class CompileFatalDiagnosticShapeTest extends TestCase
{
    public function testNeverReturnEmitsZendShapedFatal(): void
    {
        $code = '<?php function f(): never { return 1; }';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php')
            .' '.escapeshellarg('/tmp/phpc_never_ret_27718.php').' 2>&1';
        file_put_contents('/tmp/phpc_never_ret_27718.php', $code);
        $output = shell_exec($cmd) ?? '';
        @unlink('/tmp/phpc_never_ret_27718.php');

        $this->assertStringContainsString('PHP Fatal error:', $output);
        $this->assertStringNotContainsString('parseAndCompile failure:', $output);
        $this->assertStringContainsString('A never-returning function must not return', $output);
        $this->assertMatchesRegularExpression('/on line \d+/', $output);
    }

    public function testAbstractClassEmitsZendShapedFatal(): void
    {
        $code = "<?php\nabstract class A { abstract function f(); }\nclass B extends A {}";
        file_put_contents('/tmp/phpc_abstract_27718.php', $code);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php')
            .' '.escapeshellarg('/tmp/phpc_abstract_27718.php').' 2>&1';
        $output = shell_exec($cmd) ?? '';
        @unlink('/tmp/phpc_abstract_27718.php');

        $this->assertStringContainsString('PHP Fatal error:', $output);
        $this->assertStringNotContainsString('parseAndCompile failure:', $output);
        $this->assertStringContainsString('Class B contains 1 abstract method', $output);
        $this->assertStringContainsString('(A::f)', $output);
        $this->assertStringNotContainsString('(B::f)', $output);
    }

    public function testTemporaryArrayAppendEmitsZendShapedFatal(): void
    {
        $code = "<?php\n[1, 2][] = 3;\n";
        file_put_contents('/tmp/phpc_temp_write_29769.php', $code);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php')
            .' '.escapeshellarg('/tmp/phpc_temp_write_29769.php').' 2>&1';
        $output = shell_exec($cmd) ?? '';
        @unlink('/tmp/phpc_temp_write_29769.php');

        $this->assertStringContainsString('PHP Fatal error:', $output);
        $this->assertStringNotContainsString('parseAndCompile failure:', $output);
        $this->assertStringContainsString('Cannot use temporary expression in write context', $output);
        $this->assertMatchesRegularExpression('/on line \d+/', $output);
    }
}
