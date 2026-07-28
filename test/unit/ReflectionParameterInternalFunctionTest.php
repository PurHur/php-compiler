<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ReflectionParameter on internal functions (#18337). */
final class ReflectionParameterInternalFunctionTest extends TestCase
{
    public function testInternalFunctionParameterNameAndType(): void
    {
        $rt = new Runtime();
        $code = <<<'PHP'
<?php
$p = new ReflectionParameter('strlen', 0);
echo $p->getName(), "\n";
echo $p->getType()->getName(), "\n";
$map = new ReflectionParameter('array_map', 0);
echo $map->getName(), "\n";
echo $map->getType()->getName(), "\n";
PHP;
        $block = $rt->parseAndCompile($code, 'reflection_parameter_internal.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("string\nstring\ncallback\ncallable\n", $out);
    }

    /** @covers issue #24461 — zim_reflection_parameter_isVariadic on internals */
    public function testInternalFunctionParameterIsVariadic(): void
    {
        $rt = new Runtime();
        $code = <<<'PHP'
<?php
echo (new ReflectionParameter('strlen', 0))->isVariadic() ? "T\n" : "F\n";
echo (new ReflectionParameter('array_map', 2))->isVariadic() ? "T\n" : "F\n";
echo (new ReflectionParameter('call_user_func', 1))->isVariadic() ? "T\n" : "F\n";
echo (new ReflectionParameter('sprintf', 0))->isVariadic() ? "T\n" : "F\n";
echo (new ReflectionParameter('pack', 1))->isVariadic() ? "T\n" : "F\n";
PHP;
        $block = $rt->parseAndCompile($code, 'reflection_parameter_is_variadic_internal.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("F\nT\nT\nF\nT\n", $out);
    }
}
