<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Typed class constants compile gate (#12798, Zend/zend_compile.c). */
final class TypedClassConstCompileTest extends TestCase
{
    public function testTypedClassConstantRejectedOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            if (CompilerVersion::supportsTypedClassConstants()) {
                $this->markTestSkipped('PHP_COMPILER_PROFILE=8.2 unexpectedly enables typed class constants');
            }
            $code = <<<'PHP'
<?php
class C {
    public const string K = 'v';
}
echo C::K;
PHP;
            $runtime = new Runtime();
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage('syntax error, unexpected identifier "K", expecting "="');
            $runtime->parseAndCompile($code, 'typed_class_const_reject.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testTypedClassConstantCompilesOnDefaultDevProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            if (!CompilerVersion::supportsTypedClassConstants()) {
                $this->markTestSkipped('typed class constants require 8.3+ target (#19950)');
            }
            $code = <<<'PHP'
<?php
class Config {
    const string VERSION = '1.0';
    const int MAX = 100;
}
echo Config::VERSION, "\n", Config::MAX, "\n";
PHP;
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'issue_19950_typed_class_constants.php');
            $this->assertNotNull($block);
            ob_start();
            $runtime->run($block);
            $this->assertSame("1.0\n100\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testTypedClassConstantCompilesOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            if (!CompilerVersion::supportsTypedClassConstants()) {
                $this->markTestSkipped('typed class constants require forward profile 8.3+ (#12994)');
            }
            $code = <<<'PHP'
<?php
class C {
    public const string K = 'v';
    public const array X = [1, 2];
}
echo C::K, C::X[0];
PHP;
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'typed_class_const_forward.php');
            $this->assertNotNull($block);
            ob_start();
            $runtime->run($block);
            $this->assertSame('v1', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testUntypedClassConstantStillCompilesOnReferenceProfile(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public const K = 'v';
}
echo C::K;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'untyped_class_const.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('v', ob_get_clean());
    }
}
