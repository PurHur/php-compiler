<?php
declare(strict_types=1);
namespace PHPCompiler;
use PHPCompiler\ext\mysqli\MysqliExtensionPolicy;
use PHPUnit\Framework\TestCase;
/** MysqliExtensionPolicy host / ENABLE gate (#23954). */
final class MysqliExtensionPolicyTest extends TestCase
{
    public function testWithheldOnReferenceWithoutHostMysqli(): void
    {
        if (\extension_loaded('mysqli')) {
            self::markTestSkipped('host ext/mysqli loaded');
        }
        self::assertFalse(MysqliExtensionPolicy::advertisesExtension());
        $runtime = new Runtime();
        self::assertFalse(ext\standard\ModuleRegistry::extensionLoaded('mysqli'));
        self::assertFalse(ext\standard\VmReflection::functionExists($runtime->vmContext, 'mysqli_connect'));
        self::assertFalse(isset($runtime->vmContext->classes['mysqli']));
    }
    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('mysqli')) {
            self::markTestSkipped('host ext/mysqli loaded');
        }
        $prev = getenv('PHP_COMPILER_ENABLE_MYSQLI');
        putenv('PHP_COMPILER_ENABLE_MYSQLI=1');
        try {
            self::assertTrue(MysqliExtensionPolicy::advertisesExtension());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_ENABLE_MYSQLI');
            } else {
                putenv('PHP_COMPILER_ENABLE_MYSQLI='.$prev);
            }
        }
    }
}
