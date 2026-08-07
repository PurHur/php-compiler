<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** hash_file / hash_hmac_file Reflection string|false (#28318, hash.stub.php). */
final class HashFileReflectionTest extends TestCase
{
    public function testReflectionReturnIsStringOrFalse(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28318_hash_file_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28318.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "hash_file=string|false\nhash_hmac_file=string|false\nhash_file_runtime=ok\nhash_hmac_file_runtime=ok\n",
            ob_get_clean()
        );
    }
}
