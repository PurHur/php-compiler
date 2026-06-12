<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VmDate getmyinode without host \\stat() (#8167, #7844 phase 3). */
final class VmDateStatRuntimeShrinkTest extends TestCase
{
    public function testVmDateDoesNotDelegateToHostStat(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/VmDate.php');
        $this->assertStringContainsString('VmStatCache::stat', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\stat\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/[^:]\\\\stat\\s*\\(/', $source);
    }
}
