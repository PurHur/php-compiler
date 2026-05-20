<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT binaries refresh superglobals from CGI env each run (issue #201).
 *
 * @group llvm
 * @group aot
 */
final class RuntimeSuperglobalRefreshTest extends TestCase
{
    private static ?bool $llvmReady = null;

    private string $compileBin = '';

    public function setUp(): void
    {
        $this->compileBin = realpath(__DIR__ . '/../../bin/compile.php');
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
    }

    public function testTwoRequestsDifferentQueryString(): void
    {
        $source = realpath(__DIR__ . '/../../examples/001-SimpleWeb/example.php');
        $this->assertNotFalse($source);
        $outfile = tempnam(sys_get_temp_dir(), 'phpc_refresh_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$this->compileBin, '-o', $outfile, $source]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));

        $envA = $env;
        $envA['QUERY_STRING'] = 'name=Alice';
        $envA['SCRIPT_NAME'] = '/example.php';
        $envA['REQUEST_URI'] = '/example.php?name=Alice';
        $outA = $this->runBinary($outfile, $envA);
        $this->assertStringContainsString('Hello Alice', $outA);

        $envB = $env;
        $envB['QUERY_STRING'] = 'name=Bob';
        $envB['SCRIPT_NAME'] = '/example.php';
        $envB['REQUEST_URI'] = '/example.php?name=Bob';
        $outB = $this->runBinary($outfile, $envB);
        $this->assertStringContainsString('Hello Bob', $outB);

        @unlink($outfile);
    }

    public function testHttpsSchemeFromCgiEnvironment(): void
    {
        $source = <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_SERVER['REQUEST_SCHEME'], '://', $_SERVER['HTTP_HOST'];
PHP;

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_https_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$this->compileBin, '-o', $outfile]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));

        $runEnv = $env;
        $runEnv['HTTP_HOST'] = 'example.test';
        $runEnv['HTTP_X_FORWARDED_PROTO'] = 'https';
        $runEnv['SCRIPT_NAME'] = '/index.php';
        $runEnv['REQUEST_URI'] = '/index.php';
        $output = $this->runBinary($outfile, $runEnv);
        $this->assertStringContainsString('https://example.test', $output);

        @unlink($outfile);
    }

    public function testCookieFromHttpCookieEnv(): void
    {
        $source = <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_COOKIE['session'];
PHP;

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_cookie_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$this->compileBin, '-o', $outfile]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));

        $runEnv = $env;
        $runEnv['HTTP_COOKIE'] = 'session=abc123';
        $runEnv['SCRIPT_NAME'] = '/index.php';
        $runEnv['REQUEST_URI'] = '/index.php';
        $output = $this->runBinary($outfile, $runEnv);
        $this->assertStringContainsString('abc123', $output);

        $runEnv['HTTP_COOKIE'] = 'session=xyz789';
        $output2 = $this->runBinary($outfile, $runEnv);
        $this->assertStringContainsString('xyz789', $output2);
        $this->assertStringNotContainsString('abc123', $output2);

        @unlink($outfile);
    }

    public function testScriptFilenameFromCgiEnvironment(): void
    {
        $source = <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_SERVER['SCRIPT_FILENAME'];
PHP;

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_script_fn_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$this->compileBin, '-o', $outfile]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));

        $root = realpath(sys_get_temp_dir());
        $this->assertNotFalse($root);
        $runEnv = $env;
        unset($runEnv['SCRIPT_FILENAME']);
        $runEnv['DOCUMENT_ROOT'] = $root;
        $runEnv['SCRIPT_NAME'] = '/index.php';
        $runEnv['REQUEST_URI'] = '/index.php';
        $output = $this->runBinary($outfile, $runEnv);
        $expected = $root.'/index.php';
        $this->assertStringContainsString($expected, $this->cgiBody($output));

        @unlink($outfile);
    }

    public function testRemoteAddrFromCgiEnvironment(): void
    {
        $source = <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_SERVER['REMOTE_ADDR'], '|', $_SERVER['REMOTE_PORT'];
PHP;

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_remote_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$this->compileBin, '-o', $outfile]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));

        $runEnv = $env;
        $runEnv['REMOTE_ADDR'] = '203.0.113.7';
        $runEnv['REMOTE_PORT'] = '54321';
        $runEnv['SCRIPT_NAME'] = '/index.php';
        $runEnv['REQUEST_URI'] = '/index.php';
        $output = $this->runBinary($outfile, $runEnv);
        $this->assertStringContainsString('203.0.113.7|54321', $output);

        @unlink($outfile);
    }

    public function testDocumentRootFromCgiEnvironment(): void
    {
        $source = <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_SERVER['DOCUMENT_ROOT'];
PHP;

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_docroot_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$this->compileBin, '-o', $outfile]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));

        $root = realpath(sys_get_temp_dir());
        $this->assertNotFalse($root);
        $runEnv = $env;
        $runEnv['DOCUMENT_ROOT'] = $root;
        $runEnv['SCRIPT_NAME'] = '/index.php';
        $runEnv['REQUEST_URI'] = '/index.php';
        $output = $this->runBinary($outfile, $runEnv);
        $this->assertStringContainsString($root, $output);

        @unlink($outfile);
    }

    public function testHttpHostFromCgiEnvironment(): void
    {
        $source = <<<'PHP'
<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');
echo $_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_CUSTOM'];
PHP;

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_http_hdr_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$this->compileBin, '-o', $outfile]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);
        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));

        $runEnv = $env;
        $runEnv['HTTP_HOST'] = 'example.test';
        $runEnv['HTTP_X_CUSTOM'] = '1';
        $runEnv['SCRIPT_NAME'] = '/index.php';
        $runEnv['REQUEST_URI'] = '/index.php';
        $output = $this->runBinary($outfile, $runEnv);
        $this->assertStringContainsString('example.test1', $output);

        @unlink($outfile);
    }

    private function cgiBody(string $output): string
    {
        $parts = preg_split("/\r?\n\r?\n/", $output, 2);

        return $parts[1] ?? $output;
    }

    /**
     * @param array<string, string> $env
     */
    private function runBinary(string $binary, array $env): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $run = proc_open([$binary], $descriptorSpec, $pipes, null, $env);
        $result = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($run);
        $this->assertSame(0, $exitCode, 'AOT binary should exit with status 0');

        return $result !== false ? $result : '';
    }

    /**
     * @return array<string, string>
     */
    private function llvmProcessEnv(string $repoRoot): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        return $env;
    }

    private static function isLlvmReady(): bool
    {
        if (null !== self::$llvmReady) {
            return self::$llvmReady;
        }
        self::$llvmReady = LlvmToolchain::isReady(dirname(__DIR__, 2));

        return self::$llvmReady;
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            $cmd = preg_split('/\s+/', $phpEnv);
        } else {
            $cmd = [PHP_BINARY];
        }
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        if (is_dir($extDir)) {
            foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
                $so = $extDir.'/'.$ext.'.so';
                if (is_file($so)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension='.$so;
                }
            }
        }
        $cmd[] = '-d';
        $cmd[] = 'display_errors=0';

        return $cmd;
    }

    /**
     * @return list<string>
     */
    private static function llvmEnvPrefix(): array
    {
        return LlvmToolchain::envPrefix(dirname(__DIR__, 2));
    }
}
