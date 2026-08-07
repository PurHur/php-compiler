<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** openssl_error_string() Reflection string|false (#28368, openssl.stub.php). */
final class OpensslErrorStringReflectionTest extends TestCase
{
    public function testReflectionReturnIsStringOrFalse(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28368_openssl_error_string_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28368.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("return=string|false\nargc=0\nruntime=ok\n", ob_get_clean());
    }
}
