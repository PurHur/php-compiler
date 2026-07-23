<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Closure::getUsedVariables() VM builtin (#6067, #16735 profile gate). */
final class ClosureGetUsedVariablesBuiltinTest extends TestCase
{
    public function testClosureGetUsedVariablesReturnsCaptureMap(): void
    {
        if (!CompilerVersion::supportsClosureGetUsedVariables()) {
            $this->markTestSkipped('Closure::getUsedVariables() withheld — not in php-src stubs (#22583)');
        }
        $rt = new Runtime();
        $code = <<<'PHP'
<?php
$x = 1;
$y = 'two';
$c = function () use ($x, &$y) {
    return $x . $y;
};
var_export(method_exists($c, 'getUsedVariables'));
echo "\n";
$used = $c->getUsedVariables();
ksort($used);
var_export($used);
echo "\n";
PHP;
        $block = $rt->parseAndCompile($code, 'closure_get_used_variables.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("true\narray (\n  'x' => 1,\n  'y' => 'two',\n)\n", $out);
    }
}
