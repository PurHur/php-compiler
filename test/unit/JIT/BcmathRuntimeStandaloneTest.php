<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Bcmath;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #6100: AOT standalone must define bcmath helpers without C runtime files.
 *
 * @group aot-lint
 */
final class BcmathRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesBcmathRuntimeFunctions(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        Bcmath::ensureLinked($ctx);

        foreach ([
            '__compiler_bcscale',
            '__compiler_bcadd',
            '__compiler_bcsub',
            '__compiler_bcmul',
            '__compiler_bcdiv',
            '__compiler_bcmod',
            '__compiler_bccomp',
            '__compiler_bcpowmod',
        ] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
