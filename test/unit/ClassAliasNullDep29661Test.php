<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * class_alias(null) soft-null DEP cites $class like Zend (#29661).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias)
 * php-src: Zend/zend_builtin_functions.stub.php — string $class
 */
final class ClassAliasNullDep29661Test extends TestCase
{
    public function testVmDepCitesClassUnderProfile84(): void
    {
        $out = $this->runRepro('bin/vm.php');
        $this->assertDepCitesClass($out);
    }

    public function testJitDepCitesClassUnderProfile84(): void
    {
        $out = $this->runRepro('bin/jit.php');
        $this->assertDepCitesClass($out);
    }

    public function testBuiltinParamNamesRemainClassAliasAutoload(): void
    {
        $names = BuiltinParamNames::forFunction('class_alias');
        $this->assertSame(['class', 'alias', 'autoload='], $names);
        $this->assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'class', 'class_alias'));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'original class', 'class_alias'));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'original_class', 'class_alias'));
    }

    private function assertDepCitesClass(string $out): void
    {
        $this->assertStringContainsString("rf0=class\n", $out);
        $this->assertStringContainsString("rf1=alias\n", $out);
        $this->assertStringContainsString("rf2=autoload\n", $out);
        $this->assertStringContainsString(
            'class_alias(): Passing null to parameter #1 ($class) of type string is deprecated',
            $out
        );
        $this->assertStringNotContainsString('original class', $out);
        $this->assertStringContainsString('Class "" not found', $out);
        $this->assertMatchesRegularExpression('/\nfalse\s*$/', $out);
    }

    private function runRepro(string $bin): string
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_run_20260810c/class_alias_null_repro.php';
        $cmd = 'PHP_COMPILER_PROFILE=8.4 '
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/'.$bin)
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out).([] === $out ? '' : "\n");
    }
}
