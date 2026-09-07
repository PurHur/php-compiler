<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — `$strategy->named($closure)` from a method (Slim RequestResponse shape) under AOT.
 *
 * @group aot
 */
final class Issue36382StrategyNamedInvokeClosureAotTest extends TestCase
{
    public function testNamedStrategyInvokeWithClosureArg(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_strategy_invoke_closure_b2c9.php';
        $this->assertFileExists($src);
        $out = tempnam(sys_get_temp_dir(), 'strat36382_');
        $this->assertNotFalse($out);
        @unlink($out);
        putenv('PHP_COMPILER_CACHE=0');
        $_ENV['PHP_COMPILER_CACHE'] = '0';
        $cmd = sprintf(
            'php -d memory_limit=1024M -d opcache.enable_cli=0 %s -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($out),
            escapeshellarg($src)
        );
        exec($cmd, $lines, $ec);
        putenv('PHP_COMPILER_CACHE');
        unset($_ENV['PHP_COMPILER_CACHE']);
        $joined = implode("\n", $lines);
        $this->assertSame(0, $ec, $joined);
        $this->assertFileExists($out);
        exec(escapeshellarg($out).' 2>&1', $runOut, $runEc);
        @unlink($out);
        $this->assertSame(0, $runEc, implode("\n", $runOut));
        $this->assertSame(['hello'], $runOut);
    }
}
