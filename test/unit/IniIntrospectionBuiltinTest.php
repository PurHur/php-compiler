<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** php_ini_loaded_file() / php_ini_scanned_files() VM smoke (#6117). */
final class IniIntrospectionBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
echo function_exists('php_ini_loaded_file') ? "loaded_fn\n" : "missing_loaded\n";
echo function_exists('php_ini_scanned_files') ? "scanned_fn\n" : "missing_scanned\n";
$loaded = php_ini_loaded_file();
echo is_string($loaded) && '' !== $loaded ? "loaded_path\n" : (false === $loaded ? "loaded_false\n" : "loaded_bad\n");
$scanned = php_ini_scanned_files();
echo is_string($scanned) && '' !== $scanned ? "scanned_path\n" : (false === $scanned ? "scanned_false\n" : "scanned_bad\n");
putenv('PHP_COMPILER_INI_LOADED_FILE=/tmp/test.ini');
putenv('PHP_COMPILER_INI_SCANNED_FILES=/tmp/a.ini,');
echo php_ini_loaded_file() === '/tmp/test.ini' ? "loaded_override\n" : "loaded_override_bad\n";
echo php_ini_scanned_files() === '/tmp/a.ini,' ? "scanned_override\n" : "scanned_override_bad\n";
PHP;

    public function testVmIniIntrospection(): void
    {
        $out = $this->runBin('bin/vm.php', self::CODE);
        $this->assertStringStartsWith("loaded_fn\nscanned_fn\n", $out);
        $this->assertStringContainsString("loaded_override\nscanned_override\n", $out);
        $this->assertMatchesRegularExpression(
            '/loaded_fn\nscanned_fn\nloaded_(path|false)\nscanned_(path|false)\n/',
            $out
        );
    }

    public function testVmIniLoadedFileArgumentCountError(): void
    {
        $code = <<<'PHP'
try {
    php_ini_loaded_file(1);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
PHP;
        $this->assertSame(
            "ArgumentCountError\nphp_ini_loaded_file() expects exactly 0 arguments, 1 given\n",
            $this->runBin('bin/vm.php', $code)
        );
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_ini_intro_');
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $repo.'/'.$bin, $tmp], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, $env);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $stderr ?: 'run failed');
        @unlink($tmp);

        return $stdout;
    }
}
