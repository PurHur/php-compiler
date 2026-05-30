<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\extension_loaded;
use PHPCompiler\ext\standard\get_loaded_extensions;
use PHPCompiler\ext\standard\version_compare;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for version_compare/extension_loaded/get_loaded_extensions (#3204). */
final class VersionCompareBuiltinTest extends TestCase
{
    public function testVersionCompareOperator(): void
    {
        $runtime = new Runtime();
        $fn = new version_compare();
        $frame = $fn->getFrame($runtime->vmContext);
        $a = new VMVariable();
        $a->string('1.0.0');
        $b = new VMVariable();
        $b->string('1.0.1');
        $op = new VMVariable();
        $op->string('<');
        $frame->calledArgs = [$a, $b, $op];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertTrue($frame->returnVar->resolveIndirect()->toBool());
    }

    public function testExtensionLoadedStandard(): void
    {
        $runtime = new Runtime();
        $fn = new extension_loaded();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string('standard');
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertTrue($frame->returnVar->resolveIndirect()->toBool());
    }

    public function testGetLoadedExtensionsIncludesStandard(): void
    {
        $runtime = new Runtime();
        $fn = new get_loaded_extensions();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $ht = $frame->returnVar->resolveIndirect()->toArray();
        $names = [];
        foreach ($ht->iterate() as $value) {
            $names[] = $value->toString();
        }
        $this->assertContains('standard', $names);
        $this->assertContains('types', $names);
    }
}
