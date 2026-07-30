<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #11902, #12328, #25502 */
final class BuiltinAttributePhantomTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE');
    }

    public function testForwardCompatAttributeClassesAdvertisedOn84DevProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesOverrideAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesDeprecatedAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesNoDiscardAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesEnumCasesAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesDelayedTargetValidationAttributeClass());
        $this->assertFalse(CompilerVersion::advertisesCompileTimeAttributeClass());
    }

    public function testVmRegistersForwardCompatAttributeClassesOn84DevProfile(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertFalse(isset($ctx->classes['override']));
        $this->assertFalse(isset($ctx->classes['deprecated']));
        $this->assertFalse(isset($ctx->classes['nodiscard']));
        $this->assertFalse(isset($ctx->classes['enumcases']));
        $this->assertFalse(isset($ctx->classes['delayedtargetvalidation']));
        $this->assertFalse(isset($ctx->classes['compiletime']));
    }

    /** @covers issue #25502 */
    public function testDeprecatedAttributeIsInertOnReferenceProfile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('Deprecated', false));
echo "\n";
var_export(class_exists('Override', false));
echo "\n";
#[\Deprecated]
function h25502_unit() {}
var_export((new ReflectionFunction('h25502_unit'))->isDeprecated());
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_25502_unit.php'));
        $this->assertSame("false\nfalse\nfalse\n", ob_get_clean());
    }
}
