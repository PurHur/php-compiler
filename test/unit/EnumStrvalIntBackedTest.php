<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Issue #5615 — strval() on int-backed enum case must Error like Zend. */
final class EnumStrvalIntBackedTest extends TestCase
{
    public function testStrvalOnIntBackedEnumCaseThrows(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
foreach ([E::A] as $case) {
    try {
        strval($case);
        echo "fail\n";
    } catch (Error $e) {
        echo $e->getMessage(), "\n";
    }
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_strval_int.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame(
            "Object of class E could not be converted to string\n",
            ob_get_clean()
        );
    }
}
