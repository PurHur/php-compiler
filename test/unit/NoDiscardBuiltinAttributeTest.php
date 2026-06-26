<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7147 */
final class NoDiscardBuiltinAttributeTest extends TestCase
{
    public function testNoDiscardRegisteredFromExtStandard(): void
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
        $runtime->run($runtime->parseAndCompile($code, 'nodiscard_ext_standard.php'));
        $this->assertSame("true\ntrue", ob_get_clean());
    }
}
