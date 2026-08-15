<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ClassConstDynamicFetchRejector;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

final class ClassConstDynamicFetchRejectorTest extends TestCase
{
    public function testRejectsCommaSeparatedDynamicFetchOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsDynamicClassConstFetch()) {
            $this->markTestSkipped('PHP 8.3+ allows dynamic class const fetch');
        }

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('unexpected token ","');

        ClassConstDynamicFetchRejector::reject(
            "<?php\nclass C { public const FOO = 'bar'; }\n\$name = 'FOO';\necho C::{\$name}, \"\\n\";\n",
            'test.php'
        );
    }

    public function testRejectsSemicolonDynamicFetchOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsDynamicClassConstFetch()) {
            $this->markTestSkipped('PHP 8.3+ allows dynamic class const fetch');
        }

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('unexpected token ";"');

        ClassConstDynamicFetchRejector::reject(
            "<?php\nclass C { public const FOO = 'bar'; }\n\$name = 'FOO';\necho C::{\$name};\n",
            'test.php'
        );
    }

    public function testRuntimeRejectsMaintainerGapRepro(): void
    {
        if (CompilerVersion::supportsDynamicClassConstFetch()) {
            $this->markTestSkipped('PHP 8.3+ allows dynamic class const fetch');
        }

        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('unexpected token ","');
        // Inline Zend 8.2 reference-profile gap (comma after C::{$name}) — #17863 / #23760.
        $runtime->parseAndCompile(
            "<?php\nclass C { public const FOO = 'bar'; }\n\$name = 'FOO';\necho C::{\$name}, \"\\n\";\n",
            'maintainer_gap_class_const_dynamic_fetch.php'
        );
    }

    public function testAllowsLiteralBraceDerefOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsDynamicClassConstFetch()) {
            $this->markTestSkipped('PHP 8.3+ allows dynamic class const fetch');
        }

        $code = "<?php echo C::{'X'};\n";
        $this->assertSame($code, ClassConstDynamicFetchRejector::reject($code, 'test.php'));
    }

    public function testForwardProfileAllowsDynamicFetch(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsDynamicClassConstFetch());
            $code = <<<'PHP'
<?php
class C { public const FOO = 'bar'; }
$name = 'FOO';
echo C::{$name}, "\n";
PHP;
            $this->assertSame($code, ClassConstDynamicFetchRejector::reject($code, 'dynamic_fetch.php'));
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'dynamic_fetch.php');
            $this->assertNotNull($block);
            ob_start();
            $runtime->run($block);
            $this->assertSame("bar\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Issue #24823: PROFILE=8.2 must match Zend 8.2 parse error (not evaluate). */
    public function testProfile82RejectsDynamicFetch(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsDynamicClassConstFetch());
            $this->expectException(CompileFatal::class);
            $this->expectExceptionMessage('unexpected token ","');
            ClassConstDynamicFetchRejector::reject(
                "<?php\nclass C { public const X = 7; }\n\$n = 'X';\necho C::{\$n}, \"\\n\";\n",
                'dyn_class_const_profile82_parity.php'
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Issue #31182: default/unset PROFILE rejects dynamic class const fetch like Zend 8.2. */
    public function testDefaultProfileRejectsDynamicFetch(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            $this->assertFalse(CompilerVersion::supportsDynamicClassConstFetch());
            $this->expectException(CompileFatal::class);
            $this->expectExceptionMessage('unexpected token ","');
            ClassConstDynamicFetchRejector::reject(
                "<?php\nclass F { const B = 42; }\n\$n = 'B';\necho F::{\$n}, \"\\n\";\n",
                'issue_31182.php'
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Issue #23760 Done-when: ClassName::{$expr}, $obj::{$expr}, undefined → Error. */
    public function testForwardProfileIssue23760ObjectAndUndefined(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            $this->assertTrue(CompilerVersion::supportsDynamicClassConstFetch());
            $code = <<<'PHP'
<?php
class C { const RED = 'red'; }
$n = 'RED';
echo C::{$n}, "\n";
$o = new C();
echo $o::{$n}, "\n";
$bad = 'MISSING';
try { C::{$bad}; } catch (Error $e) { echo get_class($e), ':', $e->getMessage(), "\n"; }
PHP;
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'issue_23760.php');
            $this->assertNotNull($block);
            ob_start();
            $runtime->run($block);
            $this->assertSame("red\nred\nError:Undefined constant C::MISSING\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
