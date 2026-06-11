<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\VmExecutionLimits;
use PHPCompiler\Runtime;

final class VmExecutionLimitsTest extends TestCase
{
    public function testSetTimeLimitRejectedInIncludedScriptDepth(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->scriptStack->push('/tmp/main.php');
        $ctx->scriptStack->push('/tmp/include.php');
        $limits = new VmExecutionLimits();
        $this->assertFalse($limits->setTimeLimit($ctx, 60));
    }

    public function testIgnoreUserAbortToggle(): void
    {
        $limits = new VmExecutionLimits();
        $this->assertSame(0, $limits->ignoreUserAbort(null));
        $this->assertSame(0, $limits->ignoreUserAbort(true));
        $this->assertSame(1, $limits->ignoreUserAbort(null));
        $this->assertSame(1, $limits->ignoreUserAbort(false));
        $this->assertSame(0, $limits->ignoreUserAbort(null));
    }
}
