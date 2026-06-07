<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

final class VmReflectionUnitEnumExistsTest extends TestCase
{
    public function testUnitEnumExistsDistinguishesPureAndBacked(): void
    {
        $runtime = new Runtime();
        $ctx = new Context($runtime);

        $pure = new ClassEntry('Pure');
        $pure->isEnum = true;
        $pure->backedType = null;
        $ctx->classes['pure'] = $pure;
        $ctx->enums['pure'] = true;

        $backed = new ClassEntry('Backed');
        $backed->isEnum = true;
        $backed->backedType = 'string';
        $ctx->classes['backed'] = $backed;
        $ctx->enums['backed'] = true;

        $this->assertTrue(VmReflection::unitEnumExists($ctx, 'Pure'));
        $this->assertFalse(VmReflection::unitEnumExists($ctx, 'Backed'));
        $this->assertFalse(VmReflection::unitEnumExists($ctx, 'Missing'));
        $this->assertFalse(VmReflection::unitEnumExists($ctx, 'NotAnEnum'));
    }
}
