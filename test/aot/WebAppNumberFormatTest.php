<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT smoke: number_format() in a minimal web-style template (LLVM binary).
 *
 * @group llvm
 * @group aot
 */
final class WebAppNumberFormatTest extends TestCase
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

    public function testPriceTemplateCompilesAndRuns(): void
    {
        $source = <<<'PHP'
<?php
declare(strict_types=1);
$price = 19.99;
header('Content-Type: text/html; charset=UTF-8');
echo '<p>Price: $', number_format($price, 2), "</p>\n";
PHP;

        $outfile = tempnam(sys_get_temp_dir(), 'phpc_nf_web_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $result = $this->compileAndRun($source, [], $outfile);
        @unlink($outfile);

        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $result);
        $this->assertStringContainsString('<p>Price: $19.99</p>', $result);
    }

    /**
     * @param list<string> $extraArgv
     */
    private function compileAndRun(string $code, array $extraArgv, string $outfile): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $env = self::llvmEnvironment($repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compileArgv = array_merge(
            self::llvmEnvPrefix(),
            self::phpCommand(),
            array_merge([$this->compileBin], $extraArgv, ['-o', $outfile])
        );
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($compile);

        $this->assertFileExists($outfile, trim($compileErr !== false ? $compileErr : ''));
        $this->assertTrue(is_executable($outfile));

        $run = proc_open([$outfile], $descriptorSpec, $runPipes, $repoRoot, $env);
        $output = stream_get_contents($runPipes[1]);
        fclose($runPipes[0]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $exitCode = proc_close($run);
        $this->assertSame(0, $exitCode, 'AOT binary should exit with status 0');

        return $output !== false ? $output : '';
    }

    /**
     * @return array<string, string>
     */
    private static function llvmEnvironment(string $repoRoot): array
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
        $cmd[] = '-d';
        $cmd[] = 'error_reporting=0';

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
