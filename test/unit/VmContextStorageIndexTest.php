<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** O(1) global/function-static storage reverse index (#36207). */
final class VmContextStorageIndexTest extends TestCase
{
    public function testGlobalNameForStorageUsesIndex(): void
    {
        $ctx = new VmContext(new Runtime());
        $global = $ctx->ensureGlobal('answer');
        $global->int(42);

        self::assertTrue($ctx->isGlobalStorage($global));
        self::assertSame('answer', $ctx->globalNameForStorage($global));

        $alias = new Variable(Variable::TYPE_NULL);
        $alias->indirect($global);
        self::assertSame('answer', $ctx->globalNameForStorage($alias));
    }

    public function testUnsetGlobalDropsIndex(): void
    {
        $ctx = new VmContext(new Runtime());
        $global = $ctx->ensureGlobal('gone');
        self::assertSame('gone', $ctx->globalNameForStorage($global));

        $ctx->unsetGlobalsTableKey('gone');

        self::assertFalse($ctx->isGlobalStorage($global));
        self::assertNull($ctx->globalNameForStorage($global));
    }

    public function testFunctionStaticKeyForStorageUsesIndex(): void
    {
        $ctx = new VmContext(new Runtime());
        $storage = $ctx->ensureFunctionStatic('fn::counter');
        $storage->int(1);

        self::assertSame('fn::counter', $ctx->functionStaticKeyForStorage($storage));

        $alias = new Variable(Variable::TYPE_NULL);
        $alias->indirect($storage);
        self::assertSame('fn::counter', $ctx->functionStaticKeyForStorage($alias));
    }
}
