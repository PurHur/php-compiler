<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** (array) cast on VM resources matches Zend one-element NULL array (#15002). */
final class ResourceArrayCastTest extends TestCase
{
    public function testCastSupportVmResourceArrayCastShape(): void
    {
        $cast = CastSupport::vmResourceArrayCast();
        $this->assertSame(Variable::TYPE_ARRAY, $cast->type);
        $ht = $cast->toArray();
        $this->assertSame(1, $ht->getNumElements());
        $elem = $ht->findIndex(0);
        $this->assertNotNull($elem);
        $this->assertSame(Variable::TYPE_NULL, $elem->resolveIndirect()->type);
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
        $this->assertSame(Variable::TYPE_NULL, $elem->resolveIndirect()->type);
    }
}
