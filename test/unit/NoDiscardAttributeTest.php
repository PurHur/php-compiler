<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5078 */
final class NoDiscardAttributeTest extends TestCase
{
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
    }

    public function testUsedReturnDoesNotWarn(): void
    {
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
    }

    public function testVoidCastSuppressesWarning(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
ini_set('error_reporting', '32767');
#[\NoDiscard]
function must_use(): int {
    return 1;
}
error_clear_last();
(void) must_use();
$last = error_get_last();
echo null === $last ? 'none' : 'warn';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'nodiscard_void_cast.php'));
        $this->assertSame('none', ob_get_clean());
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
