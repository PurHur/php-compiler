<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Lint-first gate for examples/003-MiniWebApp (issue #246).
 *
 * @see https://github.com/PurHur/php-compiler/issues/454
 */
final class MiniWebAppSkeletonTest extends TestCase
{
    public function testSkeletonTreeExists(): void
    {
        $root = dirname(__DIR__, 2).'/examples/003-MiniWebApp';
        $this->assertDirectoryExists($root);
        $this->assertFileExists($root.'/public/index.php');
        $this->assertFileExists($root.'/src/Router.php');
        $this->assertFileExists($root.'/phpc.json');
    }

    public function testLintAllReportsClassAndMethodBlockers(): void
    {
        $root = dirname(__DIR__, 2);
        $tree = $root.'/examples/003-MiniWebApp';
        $cmd = array_merge(
            self::phpCommand(),
            [$root.'/bin/lint.php', '--all', $tree]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = ($stdout !== false ? $stdout : '').($stderr !== false ? $stderr : '');
        $this->assertSame(1, $exit, $combined);
        $this->assertStringContainsString('ClassMethod', $combined);
        $this->assertStringContainsString('Expr_MethodCall', $combined);
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
        $cmd[] = '-d';
        $cmd[] = 'display_errors=0';
        $cmd[] = '-d';
        $cmd[] = 'error_reporting=0';

        return $cmd;
    }
}
