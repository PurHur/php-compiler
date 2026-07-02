<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** (array) cast on VM resources embeds the resource zval at index 0 (#15012, #15013). */
final class ResourceArrayCastTest extends TestCase
{
    public function testCastSupportVmResourceArrayCastEmbedsSource(): void
    {
        $runtime = new \PHPCompiler\Runtime();
        $ctx = $runtime->vmContext;
        $src = new Variable();
        ResourceSupport::wrap($src, 42, ResourceState::KIND_STREAM, $ctx);

        $cast = CastSupport::vmResourceArrayCast($src);
        $this->assertSame(Variable::TYPE_ARRAY, $cast->type);
        $ht = $cast->toArray();
        $this->assertSame(1, $ht->getNumElements());
        $elem = $ht->findIndex(0);
        $this->assertNotNull($elem);
        $this->assertTrue(ResourceSupport::isVmResource($elem->resolveIndirect()));
        $this->assertSame(42, ResourceSupport::resolveHandle($elem->resolveIndirect()));
    }

    public function testCastSupportRoutesStreamResourceThroughVmResourceBranch(): void
    {
        $runtime = new \PHPCompiler\Runtime();
        $ctx = $runtime->vmContext;
        $var = new Variable();
        ResourceSupport::wrap($var, 42, ResourceState::KIND_STREAM, $ctx);
        $this->assertTrue(ResourceSupport::isVmResource($var));
        $cast = CastSupport::toArray($var);
        $this->assertSame(1, $cast->toArray()->getNumElements());
        $elem = $cast->toArray()->findIndex(0);
        $this->assertNotNull($elem);
        $this->assertTrue(ResourceSupport::isVmResource($elem->resolveIndirect()));
    }
}
