<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\standard\VmForwardStaticCall;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPUnit\Framework\TestCase;

/**
 * #27140 — forward_static_call called_scope: LSB only when instanceof calling_scope.
 */
final class ForwardStaticCallDescendantLsbTest extends TestCase
{
    public function testResolveCalledScopeUsesNamedClassWhenCallerIsAncestor(): void
    {
        $ctx = $this->contextWithChain(['A', 'B', 'C']);
        // A::viaB → callable B: A is not instanceof B → called_scope B
        self::assertSame('B', VmForwardStaticCall::resolveForwardStaticCalledScope($ctx, 'A', 'B'));
        // B::viaB → callable B: B instanceof B → B
        self::assertSame('B', VmForwardStaticCall::resolveForwardStaticCalledScope($ctx, 'B', 'B'));
        // C::viaA → callable A: C instanceof A → C
        self::assertSame('C', VmForwardStaticCall::resolveForwardStaticCalledScope($ctx, 'C', 'A'));
        // B::viaParent → calling_scope A: B instanceof A → B
        self::assertSame('B', VmForwardStaticCall::resolveForwardStaticCalledScope($ctx, 'B', 'A'));
    }

    /**
     * @param list<string> $chain root-first inheritance names
     */
    private function contextWithChain(array $chain): Context
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $parentLc = null;
        foreach ($chain as $name) {
            $entry = new ClassEntry($name);
            $entry->parentLc = $parentLc;
            $ctx->classes[strtolower($name)] = $entry;
            $parentLc = strtolower($name);
        }

        return $ctx;
    }
}
