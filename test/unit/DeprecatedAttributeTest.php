<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3569 */
final class DeprecatedAttributeTest extends TestCase
{
    public function testFormatFunctionMessage(): void
    {
        $meta = new DeprecatedMetadata('old', null);
        $this->assertSame('Function f() is deprecated, old', $meta->formatFunction('f'));

        $meta = new DeprecatedMetadata('use g() instead', '8.4');
        $this->assertSame(
            'Function f() is deprecated since 8.4, use g() instead',
            $meta->formatFunction('f')
        );

        $meta = new DeprecatedMetadata(null, '1.0');
        $this->assertSame('Function f() is deprecated since 1.0', $meta->formatFunction('f'));
    }

    public function testFunctionCallRecordsDeprecation(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
ini_set('error_reporting', '32767');
#[\Deprecated(message: "old")]
function f() {}
f();
$last = error_get_last();
echo $last['message'] ?? 'none';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'deprecated_fn.php'));
        $this->assertSame('Function f() is deprecated, old', ob_get_clean());
    }
}
