<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * preg_replace(…, null, …) $replacement — Zend soft-null DEP type array|string (#29722).
 *
 * php-src: ext/pcre/php_pcre.c / php_pcre.stub.php — array|string $replacement
 *
 * VMTest data-provider is currently blocked by unrelated --EXTENSIONS-- cases;
 * this unit guard runs the issue repro via bin/vm.php + bin/jit.php.
 * Compliance .phpt: test/compliance/cases/stdlib/preg_replace_null_replacement_dep_type*.phpt
 */
final class PregReplaceNullReplacementDepTypeTest extends TestCase
{
    public function testVmDepTypeArrayStringUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "DEP:preg_replace(): Passing null to parameter #2 (\$replacement) of type array|string is deprecated\n"
            ."''\n",
            $out
        );
    }

    public function testJitDepTypeArrayStringUnderProfile84(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "DEP:preg_replace(): Passing null to parameter #2 (\$replacement) of type array|string is deprecated\n"
            ."''\n",
            $out
        );
    }

    public function testVmStrictTypesTypeErrorUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "TypeError\n"
            ."preg_replace(): Argument #2 (\$replacement) must be of type array|string, null given\n",
            $out
        );
    }

    public function testLegacyNullReplacementStillDeletesMatch(): void
    {
        $out = $this->runBin('bin/vm.php', $this->legacyDeleteCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame("bc\nbc\n1\n", $out);
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno) {
        echo 'DEP:', $errstr, "\n";

        return true;
    }

    return false;
});
var_export(preg_replace('/a/', null, 'a'));
echo "\n";
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
declare(strict_types=1);
error_reporting(E_ALL);
try {
    preg_replace('/a/', null, 'abc');
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
PHP;
    }

    private function legacyDeleteCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL & ~E_DEPRECATED);
echo preg_replace('/a/', null, 'abc'), "\n";
$count = 0;
echo preg_replace('/a/', null, 'abc', -1, $count), "\n";
echo $count, "\n";
PHP;
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function runBin(string $bin, string $code, array $extraEnv): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_preg_repl_null_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        foreach ($extraEnv as $k => $v) {
            if ('' === $v) {
                unset($env[$k]);
            } else {
                $env[$k] = $v;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, (string) $err.(string) $out);

        return (string) $out;
    }
}
