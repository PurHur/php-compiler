<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** VM $GLOBALS isset + aliasing (issues #4423, #3413). */
final class VmGlobalsTableTest extends TestCase
{
    public function testGlobalsIssetAfterTopLevelAssign(): void
    {
        $ctx = new VmContext(new Runtime());
        $y = new Variable(Variable::TYPE_INTEGER);
        $y->int(10);
        $ctx->ensureGlobal('y')->copyFrom($y);

        $key = new Variable(Variable::TYPE_STRING);
        $key->string('y');

        self::assertTrue($ctx->globalsTableOffsetIsSet($key));
    }

    public function testGlobalsAliasWrite(): void
    {
        $ctx = new VmContext(new Runtime());
        $x = $ctx->ensureGlobal('x');
        $x->int(1);

        $key = new Variable(Variable::TYPE_STRING);
        $key->string('x');
        $ref = $ctx->globalsTableOffsetFetch($key, true);
        $two = new Variable(Variable::TYPE_INTEGER);
        $two->int(2);
        $ref->resolveIndirect()->copyFrom($two);

        self::assertSame(2, $x->resolveIndirect()->toInt());
    }
}
