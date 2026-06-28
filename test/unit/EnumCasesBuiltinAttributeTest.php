<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #13057 */
final class EnumCasesBuiltinAttributeTest extends TestCase
{
    public function testEnumCasesRegisteredFromExtStandard(): void
    {
        if (!\PHPCompiler\CompilerVersion::advertisesEnumCasesAttributeClass()) {
            $this->markTestSkipped('EnumCases attribute class not advertised on reference profile');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('EnumCases', false));
echo "\n";
var_export((new ReflectionClass('EnumCases'))->isInternal());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'enumcases_ext_standard.php'));
        $this->assertSame("true\ntrue", ob_get_clean());
    }
}
