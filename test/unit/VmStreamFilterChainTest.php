<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamFilterChain;
use PHPCompiler\ext\standard\VmStreamFilters;
use PHPUnit\Framework\TestCase;

/** VM stream filter chain — PHP path only, no runtime/*.c (#3283). */
final class VmStreamFilterChainTest extends TestCase
{
    public function testBuiltinRot13RoundTripOnRead(): void
    {
        $handle = VmFs::fopen('php://memory', 'w+');
        $this->assertNotFalse($handle);
        VmFs::fwrite($handle, 'hello');
        $filterId = VmStreamFilterChain::append($handle, 'string.rot13', VmStreamFilterChain::READ);
        $this->assertNotFalse($filterId);
        $this->assertSame('uryyb', VmFs::streamGetContents($handle, -1, 0));
        VmFs::fclose($handle);
    }

    public function testStreamFilterRegisterAddsName(): void
    {
        $this->assertTrue(VmStreamFilters::register('custom.test', 'CustomFilter'));
        $this->assertFalse(VmStreamFilters::register('custom.test', 'CustomFilter'));
        $names = VmStreamFilters::allFilterNames();
        $this->assertContains('custom.test', $names);
    }
}
