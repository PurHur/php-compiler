<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\VmExecutionLimits;
use PHPCompiler\ext\standard\VmIni;
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

    public function testBeginHonorsUnlimitedIniDefault(): void
    {
        VmIni::syncMaxExecutionTime(0);
        $limits = new VmExecutionLimits();
        $limits->begin();
        $ref = new \ReflectionProperty(VmExecutionLimits::class, 'deadline');
        $ref->setAccessible(true);
        $this->assertSame(0.0, $ref->getValue($limits));
    }

    public function testSetTimeLimitNegativeOneDisablesTimer(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $ctx->scriptStack->push('/tmp/main.php');
        $limits = new VmExecutionLimits();
        $limits->begin();
        $limits->setTimeLimit($ctx, -1);
        $ref = new \ReflectionProperty(VmExecutionLimits::class, 'deadline');
        $ref->setAccessible(true);
        $this->assertSame(0.0, $ref->getValue($limits));
        $this->assertSame('-1', VmIni::getStoredMaxExecutionTime());
    }
}
