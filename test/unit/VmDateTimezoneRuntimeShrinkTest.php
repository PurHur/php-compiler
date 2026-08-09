<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** VmDate::timezone_version_get without host ext/date delegation (#8032, #6832, #29386). */
final class VmDateTimezoneRuntimeShrinkTest extends TestCase
{
    public function testVmDateDoesNotDelegateToHostTimezoneVersionGet(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/VmDate.php');
        $this->assertStringNotContainsString("function_exists('timezone_version_get')", $source);
        $this->assertStringNotContainsString('\\timezone_version_get(', $source);
    }

    public function testTimezoneVersionReadsZoneinfoWhenPresent(): void
    {
        $version = VmDate::timezone_version_get();
        $this->assertNotSame('', $version);
        $zoneinfoPresent = \is_file('/usr/share/zoneinfo/tzdata.zi')
            || \is_file('/usr/share/zoneinfo/+VERSION');
        if ($zoneinfoPresent) {
            $this->assertNotSame('0.system', $version, 'zoneinfo present must not return sentinel');
            $this->assertMatchesRegularExpression('/^[0-9]{4}[a-z0-9.]+$/i', $version);
        } else {
            $this->assertSame('0.system', $version);
        }
    }
}
