<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for URL/Base64 builtins (#28316).
 *
 * php-src: ext/standard/url.stub.php, base64.stub.php
 */
final class Issue28316UrlBase64ExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28316.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28316.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'base64_encode:ArgumentCountError:base64_encode() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'base64_decode:ArgumentCountError:base64_decode() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'urlencode:ArgumentCountError:urlencode() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'urldecode:ArgumentCountError:urldecode() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'rawurlencode:ArgumentCountError:rawurlencode() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'rawurldecode:ArgumentCountError:rawurldecode() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString('ok:YQ==:', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
