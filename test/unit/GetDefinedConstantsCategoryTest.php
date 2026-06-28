<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmConstants;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** get_defined_constants(category:) category filter (#12947). */
final class GetDefinedConstantsCategoryTest extends TestCase
{
    public function testCoreCategoryContainsPhpVersion(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $table = VmConstants::getDefinedConstantsForCategory($ctx, 'Core');
        $found = false;
        foreach ($table->iterateKeyed(true) as [$keyVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING === $key->type && 'PHP_VERSION' === $key->toString()) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Core category should include PHP_VERSION');
    }

    public function testUnknownCategoryIsEmpty(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $table = VmConstants::getDefinedConstantsForCategory($ctx, 'NoSuchExtensionCategory');
        self::assertSame(0, $table->getNumElements());
    }
}
