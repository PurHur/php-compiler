<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * get_html_translation_table(null) soft-null DEP cites parameter #1 ($table) under PROFILE=8.4 (#29395).
 *
 * php-src: ext/standard/html.c — PHP_FUNCTION(get_html_translation_table) Z_PARAM_LONG for $table
 */
final class GetHtmlTranslationTableNullDepArgIndexTest extends TestCase
{
    public function testVmDepCitesParameterOneUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->nullTableDepProbeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame($this->expectedNullTableDepOutput(), $out);
    }

    public function testVmFlagsDepCitesParameterTwoUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->nullFlagsDepProbeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame($this->expectedNullFlagsDepOutput(), $out);
    }

    private function expectedNullTableDepOutput(): string
    {
        return "ERR[8192]: get_html_translation_table(): Passing null to parameter #1 (\$table) of type int is deprecated\n"
            ."ok\n";
    }

    private function expectedNullFlagsDepOutput(): string
    {
        return "ERR[8192]: get_html_translation_table(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."ok\n";
    }

    private function nullTableDepProbeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$table = get_html_translation_table(null);
echo isset($table['&']) ? "ok\n" : "missing &\n";
PHP;
    }

    private function nullFlagsDepProbeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$table = get_html_translation_table(HTML_SPECIALCHARS, null);
echo isset($table['&']) ? "ok\n" : "missing &\n";
PHP;
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function runBin(string $bin, string $code, array $extraEnv): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_ghtt_null_');
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
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $code, $err."\n".$out);

        return (string) $out;
    }
}
