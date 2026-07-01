<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** is_array() must reject stream-context hashtable handles (#14631). */
final class IsArrayStreamContextTest extends TestCase
{
    public function testIsTypeSourceExcludesStreamContextFromIsArray(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/types/is_type.php');
        $this->assertStringContainsString('VmStreamContext::isRepresentation', $source);
        $this->assertStringContainsString('JitStreamContextRepresentation::isRepresentation', $source);
    }
}
