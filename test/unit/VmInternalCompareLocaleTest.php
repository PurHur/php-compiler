<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\strcoll;
use PHPCompiler\ext\standard\strcmp;
use PHPCompiler\ext\standard\VmInternalCompare;
use PHPUnit\Framework\TestCase;

/** @covers issue #4745 */
final class VmInternalCompareLocaleTest extends TestCase
{
    public function testSortLocaleStringSelectsStrcollComparator(): void
    {
        $fn = VmInternalCompare::valueCompareForSortFlags(StdlibConstants::SORT_LOCALE_STRING);
        $this->assertInstanceOf(strcoll::class, $fn);
        $this->assertNotInstanceOf(strcmp::class, $fn);
    }

    public function testSortLocaleStringIgnoresCaseFlag(): void
    {
        $fn = VmInternalCompare::valueCompareForSortFlags(
            StdlibConstants::SORT_LOCALE_STRING | StdlibConstants::SORT_FLAG_CASE
        );
        $this->assertInstanceOf(strcoll::class, $fn);
    }
}
