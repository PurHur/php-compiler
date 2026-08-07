<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for basename/dirname/pathinfo (#28286).
 *
 * php-src: ext/standard/file.stub.php
 */
final class Issue28286PathHelpersExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28286.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28286.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('basename_ok:OK:b', $out);
        $this->assertStringContainsString('dirname_ok:OK:/a', $out);
        $this->assertStringContainsString('pathinfo_ok:OK:b', $out);
        $this->assertStringContainsString(
            'basename:ArgumentCountError:basename() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'dirname:ArgumentCountError:dirname() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'pathinfo:ArgumentCountError:pathinfo() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'basename0:ArgumentCountError:basename() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
