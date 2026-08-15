<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * file_get_contents/file_put_contents ArgumentCountError wording matches Zend (#30677).
 *
 * php-src: ext/standard/file.c
 */
final class Issue30677FgcFpcExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30677_fgc_fpc_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30677_fgc_fpc_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "fgc excess:ArgumentCountError:file_get_contents() expects at most 5 arguments, 6 given\n"
            ."fgc missing:ArgumentCountError:file_get_contents() expects at least 1 argument, 0 given\n"
            ."fpc excess:ArgumentCountError:file_put_contents() expects at most 4 arguments, 5 given\n"
            ."fpc missing:ArgumentCountError:file_put_contents() expects at least 2 arguments, 1 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
