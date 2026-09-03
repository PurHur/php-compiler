<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source guards for #36388 short-lived array free under thin AOT.
 */
final class Issue36388ArrayDelrefHonestyTest extends TestCase
{
    public function testDelrefDeferUsesExactObjectTypeNotMaskedArrayBit(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/Refcount.php');
        $this->assertStringContainsString(
            '$deferObjectDestroy = $this->context->builder->bitwiseAnd($deferDestroy, $isObjectExact)',
            $src,
            '{main} must not defer __hashtable__dtor via TYPE_MASKED_ARRAY object bit (#36388)'
        );
        $this->assertStringContainsString(
            'TYPE_INFO_TYPE_OBJECT',
            $src
        );
    }

    public function testValueBoxHashtableAssignDoesNotDoubleAddref(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/JitValueBox.php');
        $this->assertStringContainsString(
            'Do NOT addref here — __value__writeHashtable already retains',
            $src,
            'assignToPointer must not addref before writeHashtable (#36388 / re-#36252)'
        );
        $this->assertStringContainsString(
            'Release the temp so the value-box is the sole owner (#36388)',
            $src,
            'script-global assignToPointer must move ephemeral INIT_ARRAY temps (#36388)'
        );
    }

    public function testInitArrayEphemeralMoveFlagPresent(): void
    {
        $var = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Variable.php');
        $ht = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/HashTableWriteLlvm.php');
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('ephemeralArrayTemp', $var);
        $this->assertStringContainsString('ephemeralArrayTemp = true', $ht);
        $this->assertStringContainsString('skipAddrefForHashtableMove', $jit);
    }
}
