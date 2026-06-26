<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7145 */
final class DeprecatedBuiltinAttributeTest extends TestCase
{
    public function testDeprecatedRegisteredFromExtStandard(): void
    {
        if (!\PHPCompiler\CompilerVersion::advertisesDeprecatedAttributeClass()) {
            $this->markTestSkipped('Deprecated attribute class not advertised on reference profile');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('Deprecated', false));
echo "\n";
var_export((new ReflectionClass('Deprecated'))->isInternal());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'deprecated_ext_standard.php'));
        $this->assertSame("true\ntrue", ob_get_clean());
    }
}
