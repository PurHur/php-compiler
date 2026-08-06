<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #7222 #28345 — php-src string-backed PropertyHookType */
final class PropertyHookTypeEnumTest extends TestCase
{
        use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
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
var_export(PropertyHookType::Set->value);
echo "\n";
echo (string) (new ReflectionEnum(PropertyHookType::class))->getBackingType();
echo "\n";
var_export(PropertyHookType::Get === PropertyHookType::Get);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'property_hook_type_enum.php'));
        $this->assertSame("true\n'Get'\n'get'\n'set'\nstring\ntrue", ob_get_clean());
    }
}
