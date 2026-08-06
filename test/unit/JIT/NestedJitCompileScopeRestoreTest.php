<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * #26756: NestedJIT restore must not positionAtEnd a sealed outer block (parentless IR).
 *
 * @group aot-lint
 */
final class NestedJitCompileScopeRestoreTest extends TestCase
{
    public function testNestedJitCompileScopeRestoresViaBasicBlockHelper(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) \file_get_contents($root.'/lib/JIT/NestedJitCompileScope.php');
        $this->assertStringContainsString(
            'BasicBlockHelper::restoreInsertBlock',
            $source,
            'NestedJIT must restore sealed outer BBs via BasicBlockHelper (#26756)'
        );
        $this->assertStringNotContainsString(
            'positionAtEnd($block)',
            $source,
            'Direct positionAtEnd on captured outer block re-enters sealed BBs (#26756)'
        );
    }

    public function testNestedJitClearsForeachSlotsAndActiveFunction(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) \file_get_contents($root.'/lib/JIT/NestedJitCompileScope.php');
        $this->assertStringContainsString(
            '$context->foreachIndexSlots = []',
            $source,
            'NestedJIT must isolate foreach index allocas from the outer function (#28053)'
        );
        $this->assertStringContainsString(
            "\$context->activeFunction = ''",
            $source,
            'NestedJIT must drop outer activeFunction while insert is cleared (#28053)'
        );
        $this->assertStringContainsString(
            '$context->foreachIndexSlots = $savedForeachIndexSlots',
            $source,
            'NestedJIT must restore outer foreach index slots on exit (#28053)'
        );
    }

    public function testEntryAllocaRestoresViaBasicBlockHelper(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) \file_get_contents($root.'/lib/JIT/BasicBlockHelper.php');
        $fnPos = \strpos($source, 'function entryAllocaForFunction');
        $this->assertNotFalse($fnPos);
        $chunk = \substr($source, $fnPos, 1400);
        $this->assertStringContainsString(
            'self::restoreInsertBlock($context, $restore)',
            $chunk,
            'entryAllocaForFunction must restore open insert after alloca (#26756)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/restoreInsertBlock\(\$context,\s*\$restore\);\s*\}?\s*\/\/.*clear|clearInsertionPosition\(\)/s',
            $chunk,
            'Must not clear insert after entry alloca when restore is null/sealed (#26756)'
        );
    }

    public function testStringLiteralFromLiteralGuardsInsertBlock(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) \file_get_contents($root.'/lib/JIT/Variable.php');
        $pos = \strpos($source, 'function fromLiteral');
        $this->assertNotFalse($pos);
        $chunk = \substr($source, $pos, 2500);
        $this->assertStringContainsString(
            'string_literal_alloc_cont',
            $chunk,
            'TYPE_STRING fromLiteral must ensureOpenInsertBlock before entryAlloca (#26756)'
        );
        $this->assertStringContainsString(
            'constantStringFromString',
            $chunk,
            'TYPE_STRING fromLiteral must materialize the const global before resume (#26756)'
        );
        $this->assertStringContainsString(
            'restoreInsertBlock',
            $chunk,
            'TYPE_STRING fromLiteral must restore caller BB after const init (#26756)'
        );
    }

    public function testConstantStringFromStringRestoresInsert(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) \file_get_contents($root.'/lib/JIT/Context.php');
        $pos = \strpos($source, 'function constantStringFromString');
        $this->assertNotFalse($pos);
        $chunk = \substr($source, $pos, 2800);
        $this->assertStringContainsString(
            'BasicBlockHelper::tryGetInsertBlock',
            $chunk,
            'constantStringFromString must capture insert before init builder swap (#26756)'
        );
        $this->assertStringContainsString(
            'BasicBlockHelper::restoreInsertBlock',
            $chunk,
            'constantStringFromString must restore caller insert after init (#26756)'
        );
    }

    public function testEnsureOpenInsertBlockReusesLastOpenBb(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) \file_get_contents($root.'/lib/JIT/BasicBlockHelper.php');
        $pos = \strpos($source, 'function ensureOpenInsertBlock');
        $this->assertNotFalse($pos);
        $chunk = \substr($source, $pos, 1200);
        $this->assertStringContainsString(
            'lastOpenBasicBlock',
            $chunk,
            'ensureOpenInsertBlock must reuse last open BB when insert is cleared (#26756)'
        );
    }

    public function testVmStringCompareIdenticalEnsuresInsert(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) \file_get_contents($root.'/lib/VM/VmStringCompare.php');
        $pos = \strpos($source, 'function identical(');
        $this->assertNotFalse($pos);
        $chunk = \substr($source, $pos, 800);
        $this->assertStringContainsString(
            'jit_strcmp_identical_entry',
            $chunk,
            'identical() must ensureOpenInsertBlock before branchIf (#26756)'
        );
        $vts = \strpos($source, 'function identicalValueToString');
        $this->assertNotFalse($vts);
        $vtsChunk = \substr($source, $vts, 900);
        $this->assertStringContainsString(
            'jit_strcmp_value_to_string',
            $vtsChunk,
            'identicalValueToString must ensureOpenInsertBlock before readString (#26756)'
        );
    }

    public function testEnsureOpenInsertBlockPrefersLastOpenOnSealedInsert(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) \file_get_contents($root.'/lib/JIT/BasicBlockHelper.php');
        $pos = \strpos($source, 'function ensureOpenInsertBlock');
        $this->assertNotFalse($pos);
        $chunk = \substr($source, $pos, 1200);
        $this->assertStringContainsString(
            'lastOpenBasicBlock',
            $chunk,
            'ensureOpenInsertBlock must resume last open BB when insert is cleared/sealed (#26756)'
        );
        // Sealed-insert path must also consult lastOpenBasicBlock (not only null-insert).
        $sealedPos = \strpos($chunk, 'getTerminator()');
        $this->assertNotFalse($sealedPos);
        $afterSealed = \substr($chunk, $sealedPos);
        $this->assertStringContainsString(
            'lastOpenBasicBlock',
            $afterSealed,
            'Sealed insert must prefer last open BB over orphan append (#26756)'
        );
    }
}
