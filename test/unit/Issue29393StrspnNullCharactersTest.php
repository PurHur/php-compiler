<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * strspn() null $characters — soft-null DEP+coerce under PROFILE=8.4 (#29393).
 *
 * php-src: ext/standard/string.c PHP_FUNCTION(strspn) / string.stub.php
 * Compliance: test/compliance/cases/stdlib/strspn_null_characters_soft_forward84.phpt
 * Sibling: #29394 / Issue29394StrcspnNullCharactersTest (strcspn)
 */
final class Issue29393StrspnNullCharactersTest extends TestCase
{
    public function testVmDepThenZero(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode());
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testJitDepThenZero(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode());
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testVmStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError\n"
            ."strspn(): Argument #2 (\$characters) must be of type string, null given\n",
            $out
        );
    }

    private function expectedDepOutput(): string
    {
        return "ERR[8192]: strspn(): Passing null to parameter #2 (\$characters) of type string is deprecated\n"
            ."RESULT:0\n";
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$r = strspn('abc', null);
echo "RESULT:$r\n";
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
declare(strict_types=1);
error_reporting(E_ALL);
try {
    strspn('abc', null);
    echo "no throw\n";
} catch (\Throwable $e) {
    echo get_class($e), "\n", $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_29393_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        $env['PHP_COMPILER_PROFILE'] = '8.4';
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $path, $tmp],
            $descriptor,
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
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $err)."\n".(string) $out);

        return preg_replace('/\r\n?/', "\n", (string) $out) ?? '';
    }
}
