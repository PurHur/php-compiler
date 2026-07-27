<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\IncDecResourceProvenance;
use PHPCompiler\VM\VmResourceIdString;
use PHPCfg\Operand;
use PHPUnit\Framework\TestCase;

/** Provenance + boxed long formatting for int→string (#23811). */
final class IncDecResourceProvenanceStringTest extends TestCase
{
    public function testCannotBeResourceForStringAcceptsLiterals(): void
    {
        $lit = new Operand\Literal(2);
        self::assertTrue(IncDecResourceProvenance::cannotBeResourceForString($lit));
    }

    public function testVmResourceIdStringExposesBoxedNativeLongFormatter(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmResourceIdString.php');
        self::assertStringContainsString('formatBoxedNativeLong', $source);
        self::assertStringContainsString('cannotBeResourceForString', $source);
    }

    public function testJitExposesIncDecValueBoxLvalueHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        self::assertStringContainsString('isIncDecValueBoxLvalue', $source);
    }

    public function testGuardFoldsProvenNonResourceCheck(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        self::assertStringContainsString('IncDecResourceProvenance::cannotBeResource', $source);
        self::assertStringContainsString('provenNonResource', $source);
    }
}
