<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mbstring/iconv excess argc → Zend at-most ArgumentCountError (#30891).
 *
 * php-src: ext/mbstring/mbstring.c, ext/iconv/iconv.c
 */
final class Issue30891MbIconvExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsAtMostArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30891_mb_iconv_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30891_mb_iconv_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "mb_strlen:ArgumentCountError:mb_strlen() expects at most 2 arguments, 3 given\n"
            ."mb_convert_encoding:ArgumentCountError:mb_convert_encoding() expects at most 3 arguments, 4 given\n"
            ."iconv_strlen:ArgumentCountError:iconv_strlen() expects at most 2 arguments, 3 given\n"
            ."iconv_substr:ArgumentCountError:iconv_substr() expects at most 4 arguments, 5 given\n"
            ."iconv_strpos:ArgumentCountError:iconv_strpos() expects at most 4 arguments, 5 given\n"
            ."mb_strlen_lo:ArgumentCountError:mb_strlen() expects at least 1 argument, 0 given\n"
            ."mb_convert_encoding_lo:ArgumentCountError:mb_convert_encoding() expects at least 2 arguments, 1 given\n"
            ."iconv_strlen_lo:ArgumentCountError:iconv_strlen() expects at least 1 argument, 0 given\n"
            ."iconv_substr_lo:ArgumentCountError:iconv_substr() expects at least 2 arguments, 1 given\n"
            ."ok_strlen:2\n"
            ."ok_iconv:2\n"
            ."ok_conv:a\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('expects between', $out);
        $this->assertStringNotContainsString('ACCEPTED', $out);
    }
}
