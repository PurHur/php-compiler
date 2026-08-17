<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** crypt Reflection $salt required (#28920, basic_functions.stub.php). */
final class CryptSaltRequiredReflectionTest extends TestCase
{
    public function testReflectionSaltRequired(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28920_crypt_salt_required.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28920.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "salt_optional=0\nreq=2\ntypes=string string\nargc=ArgumentCountError\n",
            ob_get_clean()
        );
    }
}
