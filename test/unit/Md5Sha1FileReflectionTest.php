<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** md5_file / sha1_file Reflection string|false (#28347, basic_functions.stub.php). */
final class Md5Sha1FileReflectionTest extends TestCase
{
    public function testReflectionReturnIsStringOrFalse(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28347_md5_sha1_file_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28347.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "md5_file=string|false\nsha1_file=string|false\nmd5_file_runtime=ok\nsha1_file_runtime=ok\nmd5_file_missing=false\n",
            ob_get_clean()
        );
    }
}
