<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VM stderr warning display via php_error_cb (#13542, main/main.c). */
final class WarningStderrDisplayTest extends TestCase
{
    public function testUndefinedVariableWarningOnStderrByDefault(): void
    {
        $repo = dirname(__DIR__, 2);
        $script = $repo.'/test/repro/maintainer_gap_undefined_var_warning_stderr.php';
        $proc = proc_open(
            [PHP_BINARY, $repo.'/bin/vm.php', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression(
            '/PHP Warning:\s+Undefined variable \$x/',
            (string) $stderr
        );
    }
}
