<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * headers_sent() excess argc → Zend at-most ArgumentCountError (#30705).
 *
 * php-src: ext/standard/head.c PHP_FUNCTION(headers_sent)
 */
final class Issue30705HeadersSentExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsAtMostArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30705_headers_sent_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30705_headers_sent_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "hi:ArgumentCountError:headers_sent() expects at most 2 arguments, 3 given\n"
            ."hi4:ArgumentCountError:headers_sent() expects at most 2 arguments, 4 given\n"
            ."ok0:1\n"
            ."ok2:1\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('accepts at most two arguments', $out);
    }
}
