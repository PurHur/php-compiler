<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * session_destroy / tmpfile excess argc → ArgumentCountError (#30676).
 *
 * php-src: ext/session/session.c, main/streams/streams.c
 */
final class Issue30676SessionDestroyTmpfileExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30676_session_destroy_tmpfile_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30676_session_destroy_tmpfile_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'session_destroy:ArgumentCountError:session_destroy() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'tmpfile:ArgumentCountError:tmpfile() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString("session_destroy_ok\n", $out);
        $this->assertStringContainsString("tmpfile_ok\n", $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
    }
}
