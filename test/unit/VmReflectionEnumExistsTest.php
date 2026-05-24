<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

final class VmReflectionEnumExistsTest extends TestCase
{
    public function testEnumExistsUsesRegistry(): void
    {
        $runtime = new Runtime();
        $ctx = new Context($runtime);
        $ctx->enums['status'] = true;

        $this->assertTrue(VmReflection::enumExists($ctx, 'Status'));
        $this->assertTrue(VmReflection::enumExists($ctx, 'status'));
        $this->assertFalse(VmReflection::enumExists($ctx, 'Missing'));
    }
}
