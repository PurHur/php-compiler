<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5078 #23038 */
final class NoDiscardAttributeTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
    }

    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
    }

    public function testFormatFunctionMessage(): void
    {
        $meta = new NoDiscardMetadata(null);
        $this->assertSame(
            'The return value of function must_use() should either be used or intentionally ignored by casting it as (void)',
            $meta->formatFunction('must_use')
        );

        $meta = new NoDiscardMetadata('check result');
        $this->assertStringEndsWith(', check result', $meta->formatFunction('with_message'));
    }

    public function testBareCallRecordsWarning(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
ini_set('error_reporting', '32767');
#[\NoDiscard]
function must_use(): int {
    return 1;
}
must_use();
$last = error_get_last();
echo $last['message'] ?? 'none';
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'nodiscard_fn.php'));
            $out = ob_get_clean();
            $this->assertStringContainsString('must_use()', $out);
            $this->assertStringContainsString('should either be used', $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testUsedReturnDoesNotWarn(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
ini_set('error_reporting', '32767');
#[\NoDiscard]
function must_use(): int {
    return 1;
}
$x = must_use();
echo $x;
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'nodiscard_used.php'));
            $this->assertSame('1', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testVoidCastParseErrorsOnProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
try {
    eval('$b = (void)1;');
    echo "void_ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'void_cast_profile85.php'));
            $this->assertSame("ParseError\n", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #23038 */
    public function testProfile82UnusedCallIsSilent(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(\PHPCompiler\CompilerVersion::supportsNoDiscardAttribute());
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
ini_set('error_reporting', '32767');
error_clear_last();
#[\NoDiscard]
function compute(): int {
    return 42;
}
compute();
$last = error_get_last();
echo null === $last ? 'none' : 'warn';
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'nodiscard_profile82.php'));
            $this->assertSame('none', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #6992 */
    public function testNoDiscardBuiltinClassExists(): void
    {
        if (!\PHPCompiler\CompilerVersion::advertisesNoDiscardAttributeClass()) {
            $this->markTestSkipped('NoDiscard attribute class not advertised on reference profile');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('NoDiscard', false));
echo "\n";
var_export((new ReflectionClass('NoDiscard'))->isInternal());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'nodiscard_class.php'));
        $this->assertSame("true\ntrue", ob_get_clean());
    }
}
