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
}
