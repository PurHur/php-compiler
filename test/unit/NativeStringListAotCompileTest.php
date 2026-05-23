<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT compile gate for compile-time native string lists (issue #83 / LLVM verify).
 *
 * @group llvm
 * @group aot
 * @group aot-lint
 */
final class NativeStringListAotCompileTest extends TestCase
{
    public function testInArrayOnNativeStringListCompiles(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }

        $source = <<<'PHP'
<?php
$routes = array('home', 'contact');
$route = 'home';
if (!in_array($route, $routes, true)) {
    http_response_code(404);
    echo "not found\n";
} else {
    echo 'ok:', $route, "\n";
}
PHP;

        $this->assertCompileExitZero($source);
    }

    private function assertCompileExitZero(string $source): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $compileBin = realpath($repoRoot.'/bin/compile.php');
        $this->assertNotFalse($compileBin);

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_native_str_list_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $php = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL'];
        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            $php,
            [$compileBin, '-o', $outfile]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);

        @unlink($outfile);

        $this->assertSame(0, $exitCode, trim($stderr !== false ? $stderr : ''));
    }
}
