<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * class_alias() of internal classes matches Zend (#29084, re-#18290/#9211).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias)
 *
 * VMTest data-provider is currently blocked by unrelated --EXTENSIONS-- cases;
 * this unit guard runs the issue repro via bin/vm.php + bin/jit.php.
 */
final class ClassAliasInternal29084Test extends TestCase
{
    public function testVmAllowsInternalClassAliasAndDuplicateWarns(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_29084_class_alias_internal.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php')
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $joined = implode("\n", $out);
        $this->assertStringContainsString("true\ntrue\ntrue\ntrue\n", $joined);
        $this->assertStringContainsString('Cannot declare class stdClass, because the name is already in use', $joined);
        $this->assertMatchesRegularExpression('/\nfalse\s*$/', $joined);
    }

    public function testJitAllowsInternalClassAliasAndDuplicateWarns(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_29084_class_alias_internal.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/jit.php')
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $joined = implode("\n", $out);
        $this->assertStringContainsString("true\ntrue\ntrue\ntrue\n", $joined);
        $this->assertStringContainsString('Cannot declare class stdClass, because the name is already in use', $joined);
        $this->assertMatchesRegularExpression('/\nfalse\s*$/', $joined);
    }
}
