<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * highlight_file/show_source empty path: Zend E_WARNING then ValueError (#30514).
 */
final class Issue30514HighlightEmptyPathWarningTest extends TestCase
{
    public function testVmEmitsWarningThenValueError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30514_highlight_empty_path_warning.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30514_highlight_empty_path_warning.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "highlight_file:ValueError:Path cannot be empty\n"
            ."highlight_file:warnings=1:ok\n"
            ."show_source:ValueError:Path cannot be empty\n"
            ."show_source:warnings=1:ok\n",
            $out
        );
    }
}
