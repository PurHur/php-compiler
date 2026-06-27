<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\PropertyHookProfileSkipTrait;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7222 */
final class PropertyHookTypeEnumTest extends TestCase
{
    use PropertyHookProfileSkipTrait;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooks();
    }

    public function testPropertyHookTypeBuiltinEnumExists(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('PropertyHookType', false));
echo "\n";
var_export(PropertyHookType::Get->name);
echo "\n";
var_export(PropertyHookType::Get->value);
echo "\n";
var_export(PropertyHookType::Get === PropertyHookType::Get);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'property_hook_type_enum.php'));
        $this->assertSame("true\n'Get'\n0\ntrue", ob_get_clean());
    }
}
