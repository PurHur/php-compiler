<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * collectCatchOps must skip CFG jumps between TYPE_TRY and TYPE_CATCH (#2157).
 */
final class TryCatchCollectOpsTest extends TestCase
{
    public function testCollectCatchOpsSkipsJumpsBetweenTryAndCatch(): void
    {
        $handler = new Block(null);
        $try = new OpCode(OpCode::TYPE_TRY);
        $handler->addOpCode($try);
        $handler->addOpCode(new OpCode(OpCode::TYPE_JUMP));
        $handler->addOpCode(new OpCode(OpCode::TYPE_JUMP));
        $catch = new OpCode(OpCode::TYPE_CATCH);
        $catch->catchTypes = 'validationerror';
        $handler->addOpCode($catch);

        $arms = JIT\TryCatchHelper::collectCatchOps($handler, 0);
        $this->assertCount(1, $arms);
        $this->assertSame(['validationerror'], $arms[0]['catchTypes']);
    }
}
