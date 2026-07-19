<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\intl\VmIntlChar;
use PHPUnit\Framework\TestCase;

/** Direct VmIntlChar::enumCharTypes range collection (#20937). */
final class VmIntlCharEnumCharTypesTest extends TestCase
{
    public function testCollectRangesIncludeAsciiLetterAndDigit(): void
    {
        $ref = new \ReflectionClass(VmIntlChar::class);
        $method = $ref->getMethod('collectCharTypeRanges');
        $method->setAccessible(true);
        /** @var list<array{0: int, 1: int, 2: int}> $ranges */
        $ranges = $method->invoke(null);
        $this->assertGreaterThan(100, \count($ranges));

        $digit = null;
        $upper = null;
        foreach ($ranges as [$start, $limit, $type]) {
            if (null === $digit && $start <= 0x30 && $limit > 0x30) {
                $digit = [$start, $limit, $type];
            }
            if (null === $upper && $start <= 0x41 && $limit > 0x41) {
                $upper = [$start, $limit, $type];
            }
        }
        $this->assertSame([48, 58, 9], $digit);
        $this->assertSame([65, 91, 1], $upper);
        $this->assertSame([0, 32, 15], $ranges[0]);
    }
}
