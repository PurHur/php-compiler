<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Web\Superglobals;

/**
 * MCJIT embed refreshes superglobals between runs without recompile (issue #642).
 *
 * @group llvm
 * @group jit
 */
final class JitSuperglobalRefreshTest extends TestCase
{
    private static ?bool $llvmReady = null;

    public function setUp(): void
    {
        if (!self::isLlvmReady()) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
    }

    public function testTwoRunsDifferentQueryStringWithoutRecompile(): void
    {
        $source = realpath(__DIR__ . '/../../examples/001-SimpleWeb/example.php');
        $this->assertNotFalse($source);
        $code = file_get_contents($source);
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        Superglobals::populateFromEnvironment($runtime->vmContext, 'name=Alice', null);
        $block = $runtime->parseAndCompile($code, $source);
        $runtime->jit($block);

        ob_start();
        $runtime->syncJitSuperglobals('name=Alice', null);
        $runtime->run($block);
        $outAlice = ob_get_clean();
        $this->assertIsString($outAlice);
        $this->assertStringContainsString('Hello Alice', $outAlice);

        ob_start();
        $runtime->syncJitSuperglobals('name=Bob', null);
        $runtime->run($block);
        $outBob = ob_get_clean();
        $this->assertIsString($outBob);
        $this->assertStringContainsString('Hello Bob', $outBob);
        $this->assertStringNotContainsString('Hello Alice', $outBob);
    }

    public function testJitCliTwoInvocations(): void
    {
        $jitBin = realpath(__DIR__ . '/../../bin/jit.php');
        $this->assertNotFalse($jitBin);
        $example = realpath(__DIR__ . '/../../examples/001-SimpleWeb/example.php');
        $this->assertNotFalse($example);
        $repoRoot = dirname(__DIR__, 2);
        $env = $this->llvmProcessEnv($repoRoot);

        $outAlice = $this->runJit($jitBin, $example, 'name=Alice', $env, $repoRoot);
        $this->assertStringContainsString('Hello Alice', $outAlice);

        $outBob = $this->runJit($jitBin, $example, 'name=Bob', $env, $repoRoot);
        $this->assertStringContainsString('Hello Bob', $outBob);
    }

    /**
     * @param array<string, string> $env
     */
    private function runJit(
        string $jitBin,
        string $example,
        string $query,
        array $env,
        string $repoRoot
    ): string {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            array_merge(
                self::llvmEnvPrefix(),
                self::phpCommand(),
                [$jitBin, '-q', $query, $example]
            ),
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
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
