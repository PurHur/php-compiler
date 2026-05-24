<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * preg_replace_callback() VM smoke (#1177).
 */
final class PregReplaceCallbackBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
function upper_matches(array $m): string {
    return strtoupper($m[0]);
}
echo preg_replace_callback('/[a-z]+/', 'upper_matches', 'foo BAR baz'), "\n";
$bad = preg_replace_callback('(bad[pattern', 'upper_matches', 'hello');
echo $bad === false ? 'false' : 'bad', "\n";
PHP;

    private const EXPECT = <<<'TXT'
FOO BAR BAZ
false
TXT;

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo . '/' . $bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_preg_replace_cb_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . self::CODE);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $err));

        return $this->normalize((string) $out);
    }

    private function normalize(string $text): string
    {
        return preg_replace('/\r\n?/', "\n", trim($text)) ?? '';
    }
}
