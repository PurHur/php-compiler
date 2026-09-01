<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SPINE_CHUNK ext/ds chunk must resolve parent::__construct in DsFactories.php (#36155).
 *
 * Without an explicit DsFactoryFunction::__construct, ds_seq::__construct's parent call
 * lowers to ExternalMethod-null (dsfactoryfunction::__construct stub).
 */
final class DsFactoryFunctionSpineChunkConstructTest extends TestCase
{
    public function testDsFactoryFunctionDeclaresConstructorBeforeSubclasses(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/ds/DsFactories.php');
        $factoryCtor = strpos($source, 'class DsFactoryFunction extends Internal');
        $explicitCtor = strpos($source, '$this->name = $name;');
        $dsSeq = strpos($source, 'final class ds_seq extends DsFactoryFunction');
        $this->assertNotFalse($factoryCtor);
        $this->assertNotFalse($explicitCtor);
        $this->assertNotFalse($dsSeq);
        $this->assertLessThan($dsSeq, $explicitCtor);
    }
}
