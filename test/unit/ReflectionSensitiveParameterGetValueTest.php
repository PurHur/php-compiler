<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #5127 */
#[Group('reflection_sensitive_parameter')]
final class ReflectionSensitiveParameterGetValueTest extends TestCase
{
    public function testReflectionParameterGetValueUnwrapsSensitiveParameter(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(#[\SensitiveParameter] string $secret) {}
$r = new ReflectionFunction('f');
$p = $r->getParameters()[0];
$v = $p->getValue(['secret' => 'pw']);
var_export($v);
echo "\n";
var_export(get_debug_type($v));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_sensitive_parameter_getvalue.php'));
        $this->assertSame("'pw'\n'string'", ob_get_clean());
    }

    public function testSensitiveParameterAttributeOnFunctionParameter(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(#[\SensitiveParameter] string $secret) {}
$p = (new ReflectionFunction('f'))->getParameters()[0];
$attrs = $p->getAttributes('SensitiveParameter');
echo count($attrs), "\n";
echo $attrs[0]->getName();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'reflection_sensitive_parameter_attr.php'));
        $this->assertSame("1\nSensitiveParameter", ob_get_clean());
    }
}
