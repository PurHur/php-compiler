<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess/missing argc → ArgumentCountError for *_exists builtins (#28475).
 *
 * php-src: Zend/zend_builtin_functions.stub.php
 */
final class Issue28475ExistsFamilyArgcTest extends TestCase
{
    public function testVmArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28475.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28475.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        $this->assertStringContainsString(
            'function_exists/0:ArgumentCountError:function_exists() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'class_exists/0:ArgumentCountError:class_exists() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'interface_exists/0:ArgumentCountError:interface_exists() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'trait_exists/0:ArgumentCountError:trait_exists() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'enum_exists/0:ArgumentCountError:enum_exists() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'function_exists/2:ArgumentCountError:function_exists() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'class_exists/3:ArgumentCountError:class_exists() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'interface_exists/3:ArgumentCountError:interface_exists() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'trait_exists/3:ArgumentCountError:trait_exists() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'enum_exists/3:ArgumentCountError:enum_exists() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString('function_exists_ok:1', $out);
        $this->assertStringContainsString('class_exists_ok:1', $out);
        $this->assertStringContainsString('interface_exists_ok:1', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly', $out);
        $this->assertStringNotContainsString('requires one or two', $out);
    }
}
