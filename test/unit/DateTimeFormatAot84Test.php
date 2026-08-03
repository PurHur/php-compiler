<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * DateTime::format civil-literal IR avoids NestedJIT segfault under PROFILE=8.4 (#27192).
 */
final class DateTimeFormatAot84Test extends TestCase
{
    public function testCompileFormatUsesCivilLiteralFastPath(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/DateTimeFormatJitHelper.php'
        );
        $this->assertStringContainsString('tryFormatCivilLiteral', $source);
        $this->assertStringContainsString('#27192', $source);
    }

    public function testJitDateExposesCivilLiteralForDateTimeFormat(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDate.php'
        );
        $this->assertMatchesRegularExpression(
            '/public static function tryFormatCivilLiteral\s*\(/',
            $source
        );
    }
}
