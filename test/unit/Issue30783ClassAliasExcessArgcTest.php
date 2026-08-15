<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * class_alias() excess argc → Zend ArgumentCountError (#30783).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias)
 */
final class Issue30783ClassAliasExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30783_class_alias_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30783_class_alias_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "excess:ArgumentCountError:class_alias() expects at most 3 arguments, 4 given\n"
            ."short:ArgumentCountError:class_alias() expects at least 2 arguments, 1 given\n"
            ."ok:true\n"
            ."exists:true\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('this compiler build', $out);
    }
}
