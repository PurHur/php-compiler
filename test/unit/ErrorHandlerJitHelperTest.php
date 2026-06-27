<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ErrorHandlerJitHelper;
use PHPUnit\Framework\TestCase;

/** ErrorHandlerJitHelper stack lifecycle for JIT/AOT (#9472). */
final class ErrorHandlerJitHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            ErrorHandlerJitHelper::restoreApply();
        }
        parent::tearDown();
    }

    public function testSetApplyReturnsPreviousAndResolveHandlerAddr(): void
    {
        $this->assertNull(ErrorHandlerJitHelper::setApply(0x1000, \E_USER_WARNING, 'h1'));
        $this->assertSame(0x1000, ErrorHandlerJitHelper::resolveHandlerAddr(\E_USER_WARNING));
        $this->assertSame(0, ErrorHandlerJitHelper::resolveHandlerAddr(\E_NOTICE));

        $this->assertSame('h1', ErrorHandlerJitHelper::setApply(0x2000, \E_NOTICE, 'h2'));
        $this->assertSame(0x2000, ErrorHandlerJitHelper::resolveHandlerAddr(\E_NOTICE));
        $this->assertSame(0, ErrorHandlerJitHelper::resolveHandlerAddr(\E_USER_WARNING));
    }

    public function testRestoreApplyPopsStack(): void
    {
        ErrorHandlerJitHelper::setApply(0x1000, \E_ALL, 'h1');
        $this->assertTrue(ErrorHandlerJitHelper::restoreApply());
        $this->assertSame(0, ErrorHandlerJitHelper::resolveHandlerAddr(\E_ALL));
        $this->assertTrue(ErrorHandlerJitHelper::restoreApply());
    }
}
