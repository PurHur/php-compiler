<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Nested FuncCall producers feeding `new` ctor args must keep EXEC_RETURN (#36385).
 *
 * php-src: Zend/zend_compile.c zend_compile_new / zend_compile_func_call (ZEND_SEND_*).
 */
final class NewNestedFuncCallCtorArgs36385Test extends \PHPUnit\Framework\TestCase
{
    public function testNestedMkCallsAsNewArgsMatchZendOnVm(): void
    {
        $code = <<<'PHP'
<?php
final class Box {
    public $a; public $b;
    public function __construct($a, $b) { $this->a = $a; $this->b = $b; }
}
function mk(int $n): int { return $n + 1; }
function build(int $n): Box {
    return new Box(mk($n), mk($n + 1));
}
$b = build(1);
echo $b->a, '|', $b->b, "\n";
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'new_nested_mk_36385.php'));
        $this->assertSame("2|3\n", ob_get_clean());
    }

    public function testRecursiveBinaryTreesCtorArgsMatchZendVmAndAot(): void
    {
        $path = __DIR__ . '/../repro/new_nested_funccall_ctor_args_36385.php';
        $zend = $this->runZendFile($path);
        $this->assertSame("15\n", $zend);

        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile($path));
        $this->assertSame($zend, ob_get_clean(), 'VM must match Zend');

        $this->assertSame($zend, $this->compileAndRunAot($path), 'AOT must match Zend');
    }

    private function runZendFile(string $path): string
    {
        $php = getenv('PHP_8_2') ?: (getenv('PHP_BINARY') ?: PHP_BINARY);
        $out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($path) . ' 2>&1');

        return is_string($out) ? $out : '';
    }

    private function compileAndRunAot(string $path): string
    {
        $bin = tempnam(sys_get_temp_dir(), 'aot36385_');
        $this->assertNotFalse($bin);
        @unlink($bin);
        $compile = 'php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/compile.php')
            . ' -o ' . escapeshellarg($bin) . ' ' . escapeshellarg($path) . ' 2>&1';
        exec($compile, $clog, $crc);
        $this->assertSame(0, $crc, "compile failed:\n" . implode("\n", $clog));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin) . ' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, "aot run failed:\n" . implode("\n", $out));

            return implode("\n", $out) . ([] !== $out ? "\n" : '');
        } finally {
            @unlink($bin);
        }
    }
}
