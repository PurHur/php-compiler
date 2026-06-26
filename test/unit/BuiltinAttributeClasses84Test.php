<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7101 */
final class BuiltinAttributeClasses84Test extends TestCase
{
    public function testDelayedTargetValidationRegistered(): void
    {
        if (!\PHPCompiler\CompilerVersion::advertisesDelayedTargetValidationAttributeClass()) {
            $this->markTestSkipped('DelayedTargetValidation not advertised on reference profile');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('DelayedTargetValidation', false));
echo "\n";
var_export((new ReflectionClass('DelayedTargetValidation'))->isInternal());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'dtv_ext_standard.php'));
        $this->assertSame("true\ntrue", ob_get_clean());
    }

    public function testCompileTimeRegistered(): void
    {
        if (!\PHPCompiler\CompilerVersion::advertisesCompileTimeAttributeClass()) {
            $this->markTestSkipped('CompileTime not advertised on reference profile');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('CompileTime', false));
echo "\n";
var_export((new ReflectionClass('CompileTime'))->isInternal());
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'compile_time_ext_standard.php'));
        $this->assertSame("true\ntrue", ob_get_clean());
    }
}
