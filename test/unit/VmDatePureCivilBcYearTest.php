<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmDatePure;
use PHPUnit\Framework\TestCase;

/**
 * Civil breakdown for BC / year −1 timestamps (#31620).
 *
 * Packed yyyymmdd + toward-zero unpack previously yielded 0000--87--74.
 */
final class VmDatePureCivilBcYearTest extends TestCase
{
    public function testGmtimeForSetIsoDateNullEpoch(): void
    {
        // gmmktime(0,0,0,12,26,-1) / DateTime::setISODate(null,null,null) under UTC
        $ts = -62167737600;
        $tm = VmDatePure::gmtime($ts);
        $this->assertNotNull($tm);
        $this->assertSame(-1, $tm['tm_year'] + 1900);
        $this->assertSame(12, $tm['tm_mon'] + 1);
        $this->assertSame(26, $tm['tm_mday']);
        $this->assertSame(0, $tm['tm_wday']); // Sunday
    }
}
