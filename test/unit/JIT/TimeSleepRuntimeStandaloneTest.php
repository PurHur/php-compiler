<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\TimeSleepRuntime;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #5406: AOT standalone must define time sleep helpers without phpc_time_sleep.c.
 *
 * @group aot-lint
 */
final class TimeSleepRuntimeStandaloneTest extends TestCase
{
    public function testEnsureLinkedDefinesTimeSleepForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        TimeSleepRuntime::ensureLinked($ctx);

        foreach (['__compiler_time_nanosleep', '__compiler_time_sleep_until'] as $name) {
            $fn = $ctx->lookupFunction($name);
            $this->assertNotNull($fn);
            $this->assertGreaterThan(0, $fn->countBasicBlocks());
        }
    }
}
