<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * VM smoke for bin/cgi.php with CGI env only (no TCP, issues #50, #656, #666).
 * AOT wrapper smoke for bin/cgi-aot.php (issue #665).
 *
 * @group cgi
 */
final class CgiDriverTest extends TestCase
{
    private string $repoRoot;

    /** @var list<string> */
    private array $phpCmd = [];

    private string $cgiBin;

    private static ?bool $llvmReady = null;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $cgi = realpath($this->repoRoot.'/bin/cgi.php');
        if (false === $cgi) {
            $this->markTestSkipped('bin/cgi.php missing (#50)');
        }
        $this->cgiBin = $cgi;
        $this->phpCmd = self::phpCommand();
    }

    public function testSimpleWebGetViaCgiDriver(): void
    {
        $script = $this->repoRoot.'/examples/001-SimpleWeb/example.php';
        $this->assertFileExists($script);

        $env = $this->baseEnv();
        $env['REQUEST_METHOD'] = 'GET';
        $env['QUERY_STRING'] = 'name=CgiTest';
        $env['SCRIPT_NAME'] = '/example.php';
        $env['SCRIPT_FILENAME'] = $script;
        $env['REQUEST_URI'] = '/example.php?name=CgiTest';

        $out = $this->runCgi($script, $env);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('Content-Type: text/html', $out);
        $this->assertStringContainsString('CgiTest', $this->cgiBody($out));
    }

    public function testSimpleWebPostViaCgiDriver(): void
    {
        $script = $this->repoRoot.'/examples/001-SimpleWeb/example.php';
        $this->assertFileExists($script);

        $body = 'name=PostCgi';
        $env = $this->baseEnv();
        $env['REQUEST_METHOD'] = 'POST';
        $env['QUERY_STRING'] = '';
        $env['CONTENT_LENGTH'] = (string) strlen($body);
        $env['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $env['SCRIPT_NAME'] = '/example.php';
        $env['SCRIPT_FILENAME'] = $script;
        $env['REQUEST_URI'] = '/example.php';

        $out = $this->runCgi($script, $env, $body);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('PostCgi', $this->cgiBody($out));
    }

    /**
     * 003-MiniWebApp PATH_INFO routes via bin/cgi.php (issue #666, mirrors ServeTest #470).
     *
     * @group miniwebapp
     */
    public function testMiniWebAppHomeViaCgiDriver(): void
    {
        $script = $this->miniWebAppIndexScript();
        $env = $this->miniWebAppBaseEnv($script);
        $env['PATH_INFO'] = '';
        $env['REQUEST_URI'] = '/index.php';

        $out = $this->runCgi($script, $env);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('Content-Type: text/html', $out);
        $this->assertStringContainsString('MiniWebApp', $this->cgiBody($out));
    }

    /**
     * @group miniwebapp
     */
    public function testMiniWebAppHelloViaCgiDriver(): void
    {
        $script = $this->miniWebAppIndexScript();
        $env = $this->miniWebAppBaseEnv($script);
        $env['PATH_INFO'] = '/hello';
        $env['QUERY_STRING'] = 'name=CgiPath';
        $env['REQUEST_URI'] = '/index.php/hello?name=CgiPath';

        $out = $this->runCgi($script, $env);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('CgiPath', $this->cgiBody($out));
    }

    /**
     * @group miniwebapp
     */
    public function testMiniWebAppApiStatusViaCgiDriver(): void
    {
        $script = $this->miniWebAppIndexScript();
        $env = $this->miniWebAppBaseEnv($script);
        $env['PATH_INFO'] = '/api/status';
        $env['REQUEST_URI'] = '/index.php/api/status';

        $out = $this->runCgi($script, $env);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('Content-Type: application/json', $out);
        $this->assertStringContainsString('"ok":true', $this->cgiBody($out));
    }

    /**
     * @group miniwebapp
     */
    public function testMiniWebAppContactPostViaCgiDriver(): void
    {
        $script = $this->miniWebAppIndexScript();
        $body = 'name=CgiPost';
        $env = $this->miniWebAppBaseEnv($script);
        $env['REQUEST_METHOD'] = 'POST';
        $env['PATH_INFO'] = '/contact';
        $env['CONTENT_LENGTH'] = (string) strlen($body);
        $env['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $env['REQUEST_URI'] = '/index.php/contact';

        $out = $this->runCgi($script, $env, $body);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('Thank you, CgiPost', $this->cgiBody($out));
    }

    /**
     * @group miniwebapp
     */
    public function testMiniWebAppContactPostRejectsEmptyNameViaCgiDriver(): void
    {
        $script = $this->miniWebAppIndexScript();
        $body = 'name=';
        $env = $this->miniWebAppBaseEnv($script);
        $env['REQUEST_METHOD'] = 'POST';
        $env['PATH_INFO'] = '/contact';
        $env['CONTENT_LENGTH'] = (string) strlen($body);
        $env['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $env['REQUEST_URI'] = '/index.php/contact';

        $out = $this->runCgi($script, $env, $body);
        $this->assertStringContainsString('Status: 400', $out);
        $this->assertStringContainsString('Invalid contact name', $this->cgiBody($out));
    }

    public function testCgiDriverRejectsOversizedContentLength(): void
    {
        $script = $this->repoRoot.'/examples/001-SimpleWeb/example.php';
        $this->assertFileExists($script);

        $env = $this->baseEnv();
        $env['REQUEST_METHOD'] = 'POST';
        $env['CONTENT_LENGTH'] = '99999999';
        $env['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $env['SCRIPT_NAME'] = '/example.php';
        $env['SCRIPT_FILENAME'] = $script;
        $env['REQUEST_URI'] = '/example.php';
        $env['PHP_COMPILER_MAX_BODY'] = '1024';

        $out = $this->runCgi($script, $env);
        $this->assertStringContainsString('Status: 413', $out);
        $this->assertStringContainsString('Payload Too Large', $this->cgiBody($out));
    }

    /**
     * 001-SimpleWeb AOT binary via cgi-aot wrapper (issue #665).
     *
     * @group llvm
     * @group aot-link
     */
    public function testSimpleWebGetViaAotCgiWrapper(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $cgiAot = realpath($this->repoRoot.'/bin/cgi-aot.php');
        if (false === $cgiAot) {
            $this->markTestSkipped('bin/cgi-aot.php missing (#665)');
        }

        $source = $this->repoRoot.'/examples/001-SimpleWeb/example.php';
        $this->assertFileExists($source);
        $binaryDir = sys_get_temp_dir().'/phpc_cgi_aot_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($binaryDir));
        $binary = $binaryDir.'/app';
        try {
            $this->compileAotBinary($source, $binary);

            $env = $this->baseEnv();
            $env['REQUEST_METHOD'] = 'GET';
            $env['QUERY_STRING'] = 'name=AotCgi';
            $env['SCRIPT_NAME'] = '/example.php';
            $env['SCRIPT_FILENAME'] = $source;
            $env['REQUEST_URI'] = '/example.php?name=AotCgi';

            $out = $this->runCgiAot($cgiAot, $binary, $env);
            $this->assertStringContainsString('Status: 200', $out);
            $this->assertStringContainsString('Content-Type: text/html', $out);
            $this->assertStringContainsString('AotCgi', $this->cgiBody($out));
        } finally {
            @unlink($binary);
            @rmdir($binaryDir);
        }
    }

    /**
     * 003-MiniWebApp PATH_INFO routes via native binary + cgi-aot (issue #682, #764).
     *
     * @group llvm
     * @group aot-link
     * @group miniwebapp
     */
    public function testMiniWebAppHomeViaAotCgiWrapper(): void
    {
        $binary = $this->buildMiniWebAppAotBinary();
        $script = $this->miniWebAppIndexScript();
        $env = $this->miniWebAppBaseEnv($script);
        $env['PATH_INFO'] = '';
        $env['REQUEST_URI'] = '/index.php';

        $out = $this->runCgiAot($this->requireCgiAotBin(), $binary, $env);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('Content-Type: text/html', $out);
        $this->assertStringContainsString('MiniWebApp', $this->cgiBody($out));
    }

    /**
     * @group llvm
     * @group aot-link
     * @group miniwebapp
     */
    public function testMiniWebAppHelloViaAotCgiWrapper(): void
    {
        $binary = $this->buildMiniWebAppAotBinary();
        $script = $this->miniWebAppIndexScript();
        $env = $this->miniWebAppBaseEnv($script);
        $env['PATH_INFO'] = '/hello';
        $env['QUERY_STRING'] = 'name=AotCgi';
        $env['REQUEST_URI'] = '/index.php/hello?name=AotCgi';

        $out = $this->runCgiAot($this->requireCgiAotBin(), $binary, $env);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('Hello AotCgi', $this->cgiBody($out));
    }

    /**
     * @group llvm
     * @group aot-link
     * @group miniwebapp
     */
    public function testMiniWebAppApiStatusViaAotCgiWrapper(): void
    {
        $binary = $this->buildMiniWebAppAotBinary();
        $script = $this->miniWebAppIndexScript();
        $env = $this->miniWebAppBaseEnv($script);
        $env['PATH_INFO'] = '/api/status';
        $env['REQUEST_URI'] = '/index.php/api/status';

        $out = $this->runCgiAot($this->requireCgiAotBin(), $binary, $env);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('Content-Type: application/json', $out);
        $this->assertStringContainsString('"ok":true', $this->cgiBody($out));
    }

    /**
     * Deploy dist cgi-wrapper shell script (issue #665).
     *
     * @group llvm
     * @group aot-link
     */
    public function testDeployCgiWrapperRunsSimpleWebBinary(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $wrapper = realpath($this->repoRoot.'/bin/cgi-aot.sh');
        if (false === $wrapper) {
            $this->markTestSkipped('bin/cgi-aot.sh missing (#665)');
        }

        $source = $this->repoRoot.'/examples/001-SimpleWeb/example.php';
        $dist = sys_get_temp_dir().'/phpc_cgi_deploy_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dist.'/bin', 0777, true));
        $binary = $dist.'/bin/app';
        try {
            $this->compileAotBinary($source, $binary);
            copy($wrapper, $dist.'/cgi-wrapper');
            chmod($dist.'/cgi-wrapper', 0755);

            $env = $this->baseEnv();
            $env['PHPC_DEPLOY_ROOT'] = $dist;
            $env['REQUEST_METHOD'] = 'GET';
            $env['QUERY_STRING'] = 'name=DeployCgi';
            $env['SCRIPT_NAME'] = '/example.php';
            $env['REQUEST_URI'] = '/example.php?name=DeployCgi';

            $out = $this->runCgiShell($dist.'/cgi-wrapper', $env);
            $this->assertStringContainsString('Status: 200', $out);
            $this->assertStringContainsString('DeployCgi', $this->cgiBody($out));
        } finally {
            $this->removeTree($dist);
        }
    }

    private function miniWebAppIndexScript(): string
    {
        $script = $this->repoRoot.'/examples/003-MiniWebApp/public/index.php';
        if (!is_file($script)) {
            $this->markTestSkipped('examples/003-MiniWebApp/public/index.php missing (#246)');
        }

        return $script;
    }

    private function requireCgiAotBin(): string
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $cgiAot = realpath($this->repoRoot.'/bin/cgi-aot.php');
        if (false === $cgiAot) {
            $this->markTestSkipped('bin/cgi-aot.php missing (#665)');
        }

        return $cgiAot;
    }

    private function buildMiniWebAppAotBinary(): string
    {
        $this->requireCgiAotBin();
        $project = $this->repoRoot.'/examples/003-MiniWebApp';
        if (!is_file($project.'/public/index.php')) {
            $this->markTestSkipped('examples/003-MiniWebApp/public/index.php missing (#246)');
        }
        $phpc = $this->repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }

        $binary = $project.'/.phpc/bin/app';
        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $project],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderr = false !== $stderr ? $stderr : '';
        if (0 !== $exit && PhpcBuild::isUserClassAotBlocked($stderr)) {
            $this->markTestSkipped(
                '003-MiniWebApp native AOT CGI blocked: '.trim($stderr)
            );
        }
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));
        $this->assertFileExists($binary);
        $real = realpath($binary);
        $this->assertNotFalse($real);

        return $real;
    }

    /**
     * CGI env aligned with DevServer front-controller conventions (#666).
     *
     * @return array<string, string>
     */
    private function miniWebAppBaseEnv(string $script): array
    {
        $project = dirname(dirname($script));
        $public = dirname($script);
        $env = $this->baseEnv();
        $env['REQUEST_METHOD'] = 'GET';
        $env['QUERY_STRING'] = '';
        $env['SCRIPT_NAME'] = '/index.php';
        $env['SCRIPT_FILENAME'] = $script;
        $env['DOCUMENT_ROOT'] = $public;
        $env['PHPC_DEPLOY_ROOT'] = $project;

        return $env;
    }

    private function compileAotBinary(string $source, string $outfile): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $compile = proc_open(
            array_merge(
                LlvmToolchain::envPrefix($this->repoRoot),
                $this->phpCmd,
                [$this->repoRoot.'/bin/compile.php', '-o', $outfile, $source]
            ),
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        $this->assertSame(0, $exitCode, 'compile.php failed: '.trim($err !== false ? $err : ''));
        $this->assertFileExists($outfile);
        $this->assertTrue(is_executable($outfile));
    }

    /**
     * @param array<string, string> $env
     */
    private function runCgiAot(string $cgiAot, string $binary, array $env, string $stdin = ''): string
    {
        $cmd = array_merge($this->phpCmd, [$cgiAot, $binary]);

        return $this->runCgiProcess($cmd, $env, $stdin);
    }

    /**
     * @param array<string, string> $env
     */
    private function runCgiShell(string $wrapper, array $env, string $stdin = ''): string
    {
        return $this->runCgiProcess([$wrapper], $env, $stdin);
    }

    /**
     * @param list<string> $cmd
     * @param array<string, string> $env
     */
    private function runCgiProcess(array $cmd, array $env, string $stdin = ''): string
    {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptor, $pipes, null, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, trim((false !== $stderr ? $stderr : '')."\n".(false !== $stdout ? $stdout : '')));

        return false !== $stdout ? $stdout : '';
    }

    /**
     * @param array<string, string> $env
     */
    private function runCgi(string $script, array $env, string $stdin = ''): string
    {
        $cmd = array_merge($this->phpCmd, [$this->cgiBin, $script]);

        return $this->runCgiProcess($cmd, $env, $stdin);
    }

    private function cgiBody(string $output): string
    {
        $parts = preg_split("/\r\n\r\n|\n\n/", $output, 2);

        return $parts[1] ?? '';
    }

    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    private static function isLlvmReady(): bool
    {
        if (null === self::$llvmReady) {
            self::$llvmReady = LlvmToolchain::isReady(dirname(__DIR__, 2));
        }

        return self::$llvmReady;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
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

        return $cmd;
    }
}
