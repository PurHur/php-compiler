<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #36382 — clone $this must not emit a class-id case for every registered class.
 */
final class CloneThisRestrict36382Test extends TestCase
{
    public function testCloneOperandHelperResolvesThisRestriction(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/lib/JIT/CloneOperandHelper.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('resolveRestrictClassIds', $src);
        $this->assertStringContainsString('classIdsInstanceOf', $src);
        $this->assertStringContainsString('clone_restrict:', $src);
        $this->assertStringContainsString('#36382', $src);
    }

    public function testCloneObjectAcceptsRestrictList(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/Object_.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('?array $restrictToClassIds = null', $src);
        $this->assertStringContainsString('Unrestricted clone walks every registered', $src);
    }
}
