<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * zlib one-shot/file helpers ArgumentCountError wording matches Zend (#30829).
 *
 * php-src: ext/zlib/zlib.c
 */
final class Issue30829ZlibOneshotExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30829_zlib_oneshot_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30829_zlib_oneshot_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'gzcompress:ArgumentCountError:gzcompress() expects at most 3 arguments, 4 given',
            'gzdeflate:ArgumentCountError:gzdeflate() expects at most 3 arguments, 4 given',
            'gzencode:ArgumentCountError:gzencode() expects at most 3 arguments, 4 given',
            'gzinflate:ArgumentCountError:gzinflate() expects at most 2 arguments, 3 given',
            'gzdecode:ArgumentCountError:gzdecode() expects at most 2 arguments, 3 given',
            'gzuncompress:ArgumentCountError:gzuncompress() expects at most 2 arguments, 3 given',
            'zlib_encode:ArgumentCountError:zlib_encode() expects at most 3 arguments, 4 given',
            'zlib_decode:ArgumentCountError:zlib_decode() expects at most 2 arguments, 3 given',
            'gzfile:ArgumentCountError:gzfile() expects at most 2 arguments, 3 given',
            'gzopen:ArgumentCountError:gzopen() expects at most 3 arguments, 4 given',
            'readgzfile:ArgumentCountError:readgzfile() expects at most 2 arguments, 3 given',
            'ok=1',
        ] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
