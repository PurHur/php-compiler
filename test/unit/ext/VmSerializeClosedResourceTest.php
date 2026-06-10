<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\Ext;

use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** serialize() resource wire format (#5326). */
final class VmSerializeClosedResourceTest extends TestCase
{
    public function testClosedStreamResourceSerializesAsZero(): void
    {
        $ctx = new Context(new Runtime());
        if (!isset($ctx->classes[ResourceSupport::CLASS_LC])) {
            $this->markTestSkipped('Resource builtin not registered');
        }
        $var = new Variable();
        $entry = new \PHPCompiler\VM\ObjectEntry($ctx->classes[ResourceSupport::CLASS_LC]);
        $entry->constructed = true;
        $entry->resourceState = new ResourceState(99, ResourceState::KIND_STREAM);
        $var->object($entry);

        $this->assertSame('i:0;', VmSerialize::serializeValue($ctx, $var));
    }
}
