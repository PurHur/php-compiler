<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** get_class_methods() VM builtin (issue #3118). */
final class GetClassMethodsBuiltinTest extends TestCase
{
    public function testVmGetClassMethodsDirectAndInherited(): void
    {
        $code = <<<'PHP'
<?php
class Base {
    public function parentMethod(): void {}
}
class Child extends Base {
    public function childMethod(): void {}
}
$methods = get_class_methods('Child');
sort($methods);
echo count($methods), "\n";
echo in_array('parentMethod', $methods, true) ? '1' : '0';
echo in_array('childMethod', $methods, true) ? '1' : '0';
echo get_class_methods('Missing') ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'get_class_methods.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("2\n110", ob_get_clean());
    }
}
