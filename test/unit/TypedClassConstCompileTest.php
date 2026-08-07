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

    public function testTypedClassConstantRejectedOnDefaultDevProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            if (CompilerVersion::supportsTypedClassConstants()) {
                $this->markTestSkipped('default profile unexpectedly enables typed class constants (#22705)');
            }
            $code = <<<'PHP'
<?php
class C {
    public const string NAME = 'x';
}
echo C::NAME;
PHP;
            $runtime = new Runtime();
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage('syntax error, unexpected identifier "NAME", expecting "="');
            $runtime->parseAndCompile($code, 'issue_22705_typed_class_const_default.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Issue #24917 — interface typed consts share the class-form withholding on the reference profile. */
    public function testTypedInterfaceConstantRejectedOnDefaultDevProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            if (CompilerVersion::supportsInterfaceTypedConstants()) {
                $this->markTestSkipped('default profile unexpectedly enables typed interface constants (#24917)');
            }
            $code = <<<'PHP'
<?php
interface I {
    public const string X = 'a';
}
echo I::X;
PHP;
            $runtime = new Runtime();
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage('syntax error, unexpected identifier "X", expecting "="');
            $runtime->parseAndCompile($code, 'issue_24917_typed_interface_const_default.php');
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

    /** Issue #23757 repro — bare `const string` (no visibility) under forward profile. */
    public function testTypedClassConstantBareConstIssue23757(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            if (!CompilerVersion::supportsTypedClassConstants()) {
                $this->markTestSkipped('typed class constants require forward profile 8.3+ (#23757)');
            }
            $code = <<<'PHP'
<?php
class C { const string X = "hello"; }
echo C::X . "\n";
PHP;
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'issue_23757_typed_class_const.php');
            $this->assertNotNull($block);
            ob_start();
            $runtime->run($block);
            $this->assertSame("hello\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Issue #23757 — type mismatch at declaration is a compile error under forward profile. */
    public function testTypedClassConstantTypeMismatchIssue23757(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            if (!CompilerVersion::supportsTypedClassConstants()) {
                $this->markTestSkipped('typed class constants require forward profile 8.3+ (#23757)');
            }
            $code = <<<'PHP'
<?php
class C { const string X = 123; }
PHP;
            $runtime = new Runtime();
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Cannot use int as value for class constant C::X of type string'
            );
            $runtime->parseAndCompile($code, 'issue_23757_typed_class_const_mismatch.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Issue #28501 — Zend wording includes Class::CONST (#28501 / zend_compile.c). */
    public function testTypedClassConstantTypeMismatchMatchesZendWording(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsTypedClassConstants()) {
                $this->markTestSkipped('typed class constants require forward profile 8.3+ (#28501)');
            }
            $code = <<<'PHP'
<?php
class D { public const string NAME = 1; }
PHP;
            $runtime = new Runtime();
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Cannot use int as value for class constant D::NAME of type string'
            );
            $runtime->parseAndCompile($code, 'issue_28501_typed_class_const_mismatch.php');
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
