<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** pfsockopen() must not delegate to host \\pfsockopen() (#8107, phase 2 of #3384). */
final class VmPfsockopenRuntimeShrinkTest extends TestCase
{
    public function testPfsockopenBuiltinDoesNotCallHostPfsockopen(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/pfsockopen.php');
        $this->assertDoesNotMatchRegularExpression('/@\\\\pfsockopen\\(/', $source);
        $this->assertStringContainsString('VmPersistentSocket::open', $source);
    }

    public function testVmPersistentSocketUsesNativeClient(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/VmPersistentSocket.php');
        $this->assertStringContainsString('VmStreamSocketNative::client', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\pfsockopen\\(/', $source);
    }
}
