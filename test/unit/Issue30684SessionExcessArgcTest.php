<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for session_id/name/module_name (#30684).
 * session_commit alias ACE names session_commit().
 *
 * php-src: ext/session/session.c
 */
final class Issue30684SessionExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30684_session_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30684_session_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'session_id:ArgumentCountError:session_id() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'session_name:ArgumentCountError:session_name() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'session_module_name:ArgumentCountError:session_module_name() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'session_commit:ArgumentCountError:session_commit() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok_id:', $out);
        $this->assertStringContainsString('ok_name:', $out);
        $this->assertStringContainsString('ok_module:', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
        $this->assertStringNotContainsString('session_write_close() expects', $out);
    }
}
