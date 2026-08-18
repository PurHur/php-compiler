<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** posix_times Reflection array|false (#28783, ext/posix/posix.stub.php). */
final class PosixTimesReflectionReturnTest extends TestCase
{
    public function testReflectionReturnIsArrayOrFalse(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28783_posix_times_reflection_return.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28783.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "posix_times=array|false\nposix_times_runtime=ok\n",
            ob_get_clean()
        );
    }
}
