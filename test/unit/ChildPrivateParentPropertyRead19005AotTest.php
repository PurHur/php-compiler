<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: child method reading parent private via $this — undefined + TypeError (#19005).
 *
 * @see php-src Zend/zend_execute.c — zend_fetch_property / inherited-private invisible name
 *
 * @group llvm
 * @group aot
 */
final class ChildPrivateParentPropertyRead19005AotTest extends TestCase
{
    private const EXPECT = <<<'TXT'
PHP Warning:  Undefined property: Child::$secret in %s on line %d
TypeError: Child::read(): Return value must be of type string, null returned
hidden

TXT;

    private const EXPECT_PATTERN = '/^(?:PHP Warning:  Undefined property: Child::\$secret in .* on line \d+\n)?'
        .'TypeError: Child::read\(\): Return value must be of type string, null returned\n'
        ."hidden\n\$/";

    public function testVmRepro(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/maintainer_child_private_parent.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_child_private_parent.php'));
        $out = (string) ob_get_clean();
        // VM warnings go to stderr; merge for assertion when available.
        $stderr = '';
        if (\function_exists('proc_open')) {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open(
                escapeshellarg(PHP_BINARY).' '.escapeshellarg($root = dirname(__DIR__, 2).'/bin/vm.php').' '
                .escapeshellarg(dirname(__DIR__).'/repro/maintainer_child_private_parent.php'),
                $descriptors,
                $pipes,
                $root
            );
            if (\is_resource($proc)) {
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                $out = $stderr.$stdout;
            }
        }
        $this->assertMatchesRegularExpression(self::EXPECT_PATTERN, $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_child_private_parent.php';
        $bin = sys_get_temp_dir().'/phpc_aot_child_priv_19005_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        exec($compile.' 2>&1', $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $out = implode("\n", $runOut)."\n";
        $this->assertMatchesRegularExpression(self::EXPECT_PATTERN, $out);
    }
}
