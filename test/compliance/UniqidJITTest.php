<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * JIT compliance for uniqid() (#2219).
 *
 * Spawns bin/jit.php via bash + script/php-env.sh (MCJIT is unstable under PHPUnit proc_open + extension preload, #98).
 *
 * @group llvm
 * @group jit
 */
final class UniqidJITTest extends TestCase
{
    private const EXPECT = <<<'TXT'
len13
two
prefix
entropy
TXT;

    public function testUniqidJitCompliance(): void
    {
        $repo = dirname(__DIR__, 2);
        if (!LlvmToolchain::hasLibrary($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $probe = $repo.'/script/jit-runtime-probe.php';
        if (!is_file($probe)) {
            $this->markTestSkipped('jit-runtime-probe.php missing');
        }
        $probeCode = (int) shell_exec(
            'bash -lc '.escapeshellarg(
                'cd '.escapeshellarg($repo).' && source script/php-env.sh && php '
                .escapeshellarg($probe).' >/dev/null 2>&1; echo $?'
            )
        );
        if (0 !== $probeCode) {
            $this->markTestSkipped('JIT MCJIT runtime probe failed in this environment');
        }

        $script = $repo.'/var/uniqid-jit-compliance-'.getmypid().'.php';
        file_put_contents($script, <<<'PHP'
<?php
$a = uniqid();
$b = uniqid();
echo strlen($a) === 13 ? "len13\n" : "bad\n";
echo strlen($b) === 13 ? "two\n" : "bad\n";
$p = uniqid('jit_');
echo strpos($p, 'jit_') === 0 ? "prefix\n" : "bad\n";
$e = uniqid('', true);
echo strlen($e) > 21 && strpos($e, ".") !== false ? "entropy\n" : "bad\n";
PHP
        );
        $bash = <<<'BASH'
set -euo pipefail
ROOT=%s
SCRIPT=%s
source "$ROOT/script/php-env.sh"
unset PHP_COMPILER_SKIP_LLVM_PRELOAD
OUT=$("$PHP_BIN" "${PHP_OPTS[@]}" "$ROOT/bin/jit.php" "$SCRIPT" 2>&1) || {
  echo "$OUT" >&2
  exit 1
}
printf '%s' "$OUT"
BASH;
        $cmd = sprintf($bash, escapeshellarg($repo), escapeshellarg($script));
        $output = shell_exec('bash -lc '.escapeshellarg($cmd));
        @unlink($script);
        if (!is_string($output)) {
            $this->fail('uniqid JIT subprocess produced no output');
        }
        $this->assertSame(self::EXPECT, preg_replace("/\r\n?/", "\n", $output));
    }
}
