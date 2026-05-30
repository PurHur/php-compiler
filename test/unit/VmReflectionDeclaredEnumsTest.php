<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** VM helper for get_declared_enums() (issue #3538). */
final class VmReflectionDeclaredEnumsTest extends TestCase
{
    public function testDeclaredEnumsTableListsEnumNamesOnly(): void
    {
        $ctx = new Context(new Runtime());
        $color = new ClassEntry('Color');
        $color->isEnum = true;
        $ctx->classes['color'] = $color;
        $ctx->enums['color'] = true;
        $size = new ClassEntry('Size');
        $size->isEnum = true;
        $ctx->classes['size'] = $size;
        $ctx->enums['size'] = true;
        $ctx->classes['foo'] = new ClassEntry('Foo');

        $names = [];
        foreach (VmReflection::declaredEnumsTable($ctx)->iterate(true) as $var) {
            $resolved = $var->resolveIndirect();
            if (Variable::TYPE_STRING === $resolved->type) {
                $names[] = $resolved->toString();
            }
        }

        $this->assertSame(['Color', 'Size'], $names);
    }
}
