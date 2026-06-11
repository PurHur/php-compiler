<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** VmDate::timezone_version_get without host ext/date delegation (#8032, #6832 phase 2). */
final class VmDateTimezoneRuntimeShrinkTest extends TestCase
{
    public function testVmDateDoesNotDelegateToHostTimezoneVersionGet(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/VmDate.php');
        $this->assertStringNotContainsString("function_exists('timezone_version_get')", $source);
        $this->assertStringNotContainsString('\\timezone_version_get(', $source);
    }

    public function testTimezoneVersionReturnsSystemDbSentinel(): void
    {
        $this->assertSame('0.system', VmDate::timezone_version_get());
    }
}
