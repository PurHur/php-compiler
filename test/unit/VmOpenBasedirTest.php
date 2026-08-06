<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmOpenBasedir;
use PHPUnit\Framework\TestCase;

/** open_basedir shared gate (#28138, main/fopen_wrappers.c). */
final class VmOpenBasedirTest extends TestCase
{
    protected function tearDown(): void
    {
        VmOpenBasedir::restore();
        parent::tearDown();
    }

    public function testDefaultEmptyAndSetRoundTrip(): void
    {
        $this->assertSame('', VmOpenBasedir::get());
        $prev = VmOpenBasedir::set('/tmp');
        $this->assertSame('', $prev);
        $this->assertNotSame('', VmOpenBasedir::get());
        $this->assertTrue(VmOpenBasedir::isActive());
    }

    public function testCannotClearAtRuntime(): void
    {
        VmOpenBasedir::set('/tmp');
        $this->assertFalse(VmOpenBasedir::set(''));
        $this->assertNotSame('', VmOpenBasedir::get());
    }

    public function testPathDenialAndAllow(): void
    {
        VmOpenBasedir::set('/tmp');
        $this->assertFalse(VmOpenBasedir::check('/etc/hosts', false));
        $tmp = \tempnam('/tmp', 'obd_ut_');
        $this->assertNotFalse($tmp);
        $this->assertTrue(VmOpenBasedir::check($tmp, false));
        @\unlink($tmp);
    }

    public function testTightenOnlyRejectsBroaderRoot(): void
    {
        VmOpenBasedir::set('/tmp');
        $this->assertFalse(VmOpenBasedir::set('/'));
    }
}
