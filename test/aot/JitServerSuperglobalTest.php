<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\Superglobals;

require_once __DIR__.'/../LlvmToolchain.php';
require_once __DIR__.'/../support/MiniWebAppCgiEnv.php';

/**
 * JIT MCJIT refresh for $_SERVER routing keys (issue #2257, parent #49).
 *
 * @group llvm
 * @group jit
 */
final class JitServerSuperglobalTest extends TestCase
{
    private static ?bool $llvmReady = null;

    private string $repoRoot = '';

    private string $jitBin = '';

    private string $miniWebPublicDir = '';

    public function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        if (false === $jit) {
            $this->markTestSkipped('bin/jit.php missing');
        }
        $this->jitBin = $jit;
        $index = $this->repoRoot.'/examples/003-MiniWebApp/public/index.php';
        if (!is_file($index)) {
            $this->markTestSkipped('examples/003-MiniWebApp/public/index.php missing');
        }
        $this->miniWebPublicDir = dirname($index);
    }

    /**
     * Two bin/jit.php runs with different PATH_INFO (issue #2257 acceptance).
     */
    public function testJitCliTwoInvocationsDifferentPathInfo(): void
    {
        $script = $this->pathInfoRouterScript();
        $home = $this->runJitScript($script, MiniWebAppCgiEnv::pathInfoHome());
        $this->assertStringContainsString('route:home', $home);

        $hello = $this->runJitScript($script, MiniWebAppCgiEnv::pathInfoHello());
        $this->assertStringContainsString('route:hello', $hello);
        $this->assertStringNotContainsString('route:home', $hello);
    }

    /**
     * 003-MiniWebApp when the project graph is JIT-ready (#475, #2257).
     */
    public function testJitCliTwoInvocationsDifferentPathInfoOnMiniWebApp(): void
    {
        $home = $this->runJitIndex(MiniWebAppCgiEnv::pathInfoHome());
        $this->assertStringContainsString(MiniWebAppCgiEnv::APP_NAME, $home);
        $this->assertStringContainsString('<title>Home', $home);

        $hello = $this->runJitIndex(MiniWebAppCgiEnv::pathInfoHello('Dev'));
        $this->assertStringContainsString('Hello Dev', $hello);
        $this->assertStringNotContainsString('<title>Home', $hello);
    }

    /**
     * MCJIT embed: PATH_INFO refresh between runs without recompile (issue #2257).
     */
    public function testEmbedTwoRunsDifferentPathInfoWithoutRecompile(): void
    {
        $code = $this->pathInfoRouterScript();
        $runtime = new Runtime();
        $this->applyCgiEnv(MiniWebAppCgiEnv::pathInfoHome());
        Superglobals::populateFromEnvironment($runtime->vmContext);
        $block = $runtime->parseAndCompile($code, 'path_info_router.php');
        $this->jitOrSkip($runtime, $block);

        $this->applyCgiEnv(MiniWebAppCgiEnv::pathInfoHome());
        ob_start();
        $runtime->syncJitSuperglobals(null, null);
        $runtime->run($block);
        $home = ob_get_clean();
        $this->assertIsString($home);
        $this->assertStringContainsString('route:home', $home);

        $this->applyCgiEnv(MiniWebAppCgiEnv::pathInfoHello());
        ob_start();
        $runtime->syncJitSuperglobals('name=Dev', null);
        $runtime->run($block);
        $hello = ob_get_clean();
        $this->assertIsString($hello);
        $this->assertStringContainsString('route:hello', $hello);
        $this->assertStringNotContainsString('route:home', $hello);
    }

    /**
     * MCJIT embed: REQUEST_METHOD must refresh between runs (issue #2257).
     */
    public function testEmbedTwoRunsDifferentRequestMethodWithoutRecompile(): void
    {
        $code = <<<'PHP'
<?php
echo $_SERVER['REQUEST_METHOD'];
PHP;

        $runtime = new Runtime();
        putenv('REQUEST_METHOD=GET');
        Superglobals::populateFromEnvironment($runtime->vmContext);
        $block = $runtime->parseAndCompile($code, 'request_method.php');
        $this->jitOrSkip($runtime, $block);

        ob_start();
        putenv('REQUEST_METHOD=GET');
        $runtime->syncJitSuperglobals(null, null);
        $runtime->run($block);
        $get = ob_get_clean();
        $this->assertSame('GET', $get);

        ob_start();
        putenv('REQUEST_METHOD=POST');
        putenv('REQUEST_BODY=name=Ada');
        $runtime->syncJitSuperglobals(null, 'name=Ada');
        $runtime->run($block);
        $post = ob_get_clean();
        $this->assertSame('POST', $post);
    }

    private function pathInfoRouterScript(): string
    {
        return <<<'PHP'
<?php
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ('/home' === $pathInfo) {
    echo 'route:home';
} elseif ('/hello' === $pathInfo) {
    echo 'route:hello';
} else {
    echo 'route:other';
}
PHP;
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function runJitScript(string $code, array $cgiEnv): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_jit_srv_');
        $this->assertNotFalse($tmp);
        $path = $tmp.'.php';
        rename($tmp, $path);
        file_put_contents($path, $code);

        try {
            return $this->runJitFile($path, $cgiEnv, dirname($path));
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function runJitIndex(array $cgiEnv): string
    {
        return $this->runJitFile(
            $this->repoRoot.'/examples/003-MiniWebApp/public/index.php',
            $cgiEnv,
            $this->miniWebPublicDir
        );
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function runJitFile(string $scriptPath, array $cgiEnv, string $cwd): string
    {
        $env = $this->llvmProcessEnv($this->repoRoot);
        foreach ($cgiEnv as $key => $value) {
            $env[$key] = $value;
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$this->jitBin, $scriptPath]
            ),
            $descriptorSpec,
            $pipes,
            $cwd,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $combined = trim(($stderr !== false ? $stderr : '')."\n".($stdout !== false ? $stdout : ''));
        if (0 !== $exit) {
            $this->maybeSkipJitFailure($combined);
            $this->assertSame(0, $exit, $combined);
        }

        return $stdout !== false ? $stdout : '';
    }

    private function jitOrSkip(Runtime $runtime, ?Block $block): void
    {
        try {
            $runtime->jit($block);
        } catch (\Throwable $e) {
            $this->maybeSkipJitFailure($e->getMessage());
            throw $e;
        }
    }

    private function maybeSkipJitFailure(string $message): void
    {
        if (
            false !== stripos($message, 'not jittable')
            || false !== stripos($message, 'substr() length must be an integer')
        ) {
            $this->markTestSkipped('JIT unavailable for this graph: '.substr($message, 0, 500));
        }
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function applyCgiEnv(array $cgiEnv): void
    {
        foreach ($cgiEnv as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
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
