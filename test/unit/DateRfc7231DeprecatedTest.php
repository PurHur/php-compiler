<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\DateConstants;
use PHPUnit\Framework\TestCase;

/** DATE_RFC7231 / RFC7231 #[\Deprecated] under PROFILE≥8.5 (#28134). */
final class DateRfc7231DeprecatedTest extends TestCase
{
    public function testMetadataNullBelow85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertNull(DateConstants::rfc7231DeprecatedMetadata());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testMetadataPresentAt85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $meta = DateConstants::rfc7231DeprecatedMetadata();
            $this->assertNotNull($meta);
            $this->assertSame(DateConstants::RFC7231_DEPRECATED_SINCE, $meta->since);
            $this->assertSame(DateConstants::RFC7231_DEPRECATED_MESSAGE, $meta->message);
            $msg = $meta->formatGlobalConstant('DATE_RFC7231');
            $this->assertStringContainsString('DATE_RFC7231 is deprecated since 8.5', $msg);
            $this->assertStringContainsString(DateConstants::RFC7231_DEPRECATED_MESSAGE, $msg);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
