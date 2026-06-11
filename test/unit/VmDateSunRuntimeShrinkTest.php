<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VmDateSunNative gmt offset without host date_offset_get delegation (#8012, #8010 phase 2). */
final class VmDateSunRuntimeShrinkTest extends TestCase
{
    public function testCurrentGmtOffsetUsesNativeTimezoneOffset(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDateSunNative.php');
        $this->assertStringContainsString('VmDateTimeNative::timezoneOffsetSeconds', $source);
        $this->assertStringNotContainsString('date_offset_get', $source);
        $this->assertStringNotContainsString('timezone_transitions_get', $source);
        $this->assertStringNotContainsString('timezone_open', $source);
    }
}
