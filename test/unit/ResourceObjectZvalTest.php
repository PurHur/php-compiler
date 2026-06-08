<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM Resource object zvals (#7073). */
final class ResourceObjectZvalTest extends TestCase
{
    public function test_fopen_returns_resource_object(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $this->assertTrue(ResourceSupport::hasResourceClass($ctx));

        $var = new VMVariable();
        $handle = \PHPCompiler\ext\standard\VmFs::fopen('php://memory', 'r+');
        $this->assertIsInt($handle);
        $var->streamHandle($handle, $ctx);

        $this->assertSame(VMVariable::TYPE_OBJECT, $var->type);
        $this->assertTrue(ResourceSupport::isResourceObject($var->toObject()));
        $this->assertTrue(ResourceSupport::isStreamResource($var));
        $this->assertSame('resource (stream)', ResourceSupport::debugTypeName($var));
        $this->assertSame($handle, ResourceSupport::resolveHandle($var));
    }
}
