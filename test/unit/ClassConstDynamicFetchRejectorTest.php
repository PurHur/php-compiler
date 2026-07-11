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
        $runtime->parseAndCompile(
            file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_class_const_dynamic_fetch.php'),
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
}
