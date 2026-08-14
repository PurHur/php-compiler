<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * zlib stream helpers ArgumentCountError wording matches Zend (#30830).
 *
 * php-src: ext/zlib/zlib.c
 */
final class Issue30830ZlibStreamExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30830_zlib_stream_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30830_zlib_stream_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'gzclose:ArgumentCountError:gzclose() expects exactly 1 argument, 2 given',
            'gzeof:ArgumentCountError:gzeof() expects exactly 1 argument, 2 given',
            'gzgetc:ArgumentCountError:gzgetc() expects exactly 1 argument, 2 given',
            'gzgets:ArgumentCountError:gzgets() expects at most 2 arguments, 3 given',
            'gzpassthru:ArgumentCountError:gzpassthru() expects exactly 1 argument, 2 given',
            'gzrewind:ArgumentCountError:gzrewind() expects exactly 1 argument, 2 given',
            'gzseek:ArgumentCountError:gzseek() expects at most 3 arguments, 4 given',
            'gztell:ArgumentCountError:gztell() expects exactly 1 argument, 2 given',
            'gzread:ArgumentCountError:gzread() expects exactly 2 arguments, 3 given',
            'gzwrite:ArgumentCountError:gzwrite() expects at most 3 arguments, 4 given',
            'gzputs:ArgumentCountError:gzputs() expects at most 3 arguments, 4 given',
            'ok=1',
        ] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
