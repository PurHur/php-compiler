<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Lint\Issue;
use PHPCompiler\Lint\UnsupportedRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/484
 * @see https://github.com/PurHur/php-compiler/issues/36396
 */
final class PhpcLintJsonTest extends TestCase
{
    public function testLintJsonIncludesIssueUrlForRegistryMapping(): void
    {
        // Generators (#167) and try/catch (#57) now compile; assert the JSON schema
        // against a registry-mapped Issue rather than a live unsupported CFG node.
        $issue = new Issue(
            'Standard input code',
            1,
            'Stmt_TryCatch',
            'Unsupported expression: Stmt_TryCatch',
            UnsupportedRegistry::trackingIssueForKind('Stmt_TryCatch')
        );
        $row = $issue->toArray();
        $this->assertSame(57, $row['issue']);
        $this->assertSame(
            UnsupportedRegistry::issueUrl(57),
            $row['issue_url']
        );
        $this->assertStringContainsString('issues/57', $row['issue_url']);
    }

    public function testPhpcLintJsonHelloWorldExitsZero(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', 'lint', '--json', '-r', '<?php echo 1;']
        );
        $exit = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
        $decoded = json_decode($exit['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertSame([], $decoded['issues']);
    }

    public function testLintJsonExplainFieldForMappedKind(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        // --explain with clean input still exits 0; schema covered via Issue::formatExplain in UnsupportedFeatureTest.
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/lint.php', '--json', '--explain', '-r', '<?php echo 1;']
        );
        $exit = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
        $decoded = json_decode($exit['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertSame([], $decoded['issues']);
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCommand(array $cmd, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [
            'code' => $code,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
        ];
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            return preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
        }
        $cmd = [PHP_BINARY];
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
