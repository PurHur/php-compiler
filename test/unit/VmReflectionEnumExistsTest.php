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
        $entry = new \PHPCompiler\VM\ClassEntry('Status');
        $entry->isEnum = true;
        $ctx->classes['status'] = $entry;
        $ctx->enums['status'] = true;

        $this->assertTrue(VmReflection::enumExists($ctx, 'Status'));
        $this->assertTrue(VmReflection::enumExists($ctx, 'status'));
        $this->assertFalse(VmReflection::enumExists($ctx, 'Missing'));
    }

    public function testEnumExistsFalseBeforeRuntimeRegistration(): void
    {
        $runtime = new Runtime();
        $ctx = new Context($runtime);
        $ctx->enums['notyet'] = true;

        $this->assertFalse(VmReflection::enumExists($ctx, 'NotYet', false));
    }
}
