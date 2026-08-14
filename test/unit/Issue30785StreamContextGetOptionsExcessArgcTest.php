<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * stream_context_get_options() ArgumentCountError wording matches Zend (#30785).
 *
 * php-src: ext/standard/streamsfuncs.c
 */
final class Issue30785StreamContextGetOptionsExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30785_stream_context_get_options_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30785_stream_context_get_options_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'hi:ArgumentCountError:stream_context_get_options() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'lo:ArgumentCountError:stream_context_get_options() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString('ok=1', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
