<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** VmErrorLog type-3 file append must not delegate to host file_put_contents (#8613, pairs #8487). */
final class VmErrorLogRuntimeShrinkTest extends TestCase
{
    public function testVmErrorLogDoesNotDelegateToHostFilePutContents(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmErrorLog.php');
        $this->assertStringContainsString('VmFs::filePutContents', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\file_put_contents\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\file_put_contents\\s*\\(/', $source);
    }
}
