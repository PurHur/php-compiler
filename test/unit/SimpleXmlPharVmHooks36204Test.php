<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\ext\phar\VmPhar;
use PHPCompiler\ext\simplexml\SimpleXmlJsonExport;
use PHPCompiler\VM\Builtin\PharRunning;
use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\PharVmRuntimeSupport;
use PHPCompiler\VM\SimpleXmlVmRuntimeSupport;
use PHPUnit\Framework\TestCase;

/**
 * lib/VM must not import ext\simplexml / ext\phar — hooks via VmRuntimeSupport (#36204).
 */
final class SimpleXmlPharVmHooks36204Test extends TestCase
{
    public function testLibSourcesHaveNoDirectExtImports(): void
    {
        $cast = (string) file_get_contents(dirname(__DIR__, 2).'/lib/VM/CastSupport.php');
        $phar = (string) file_get_contents(dirname(__DIR__, 2).'/lib/VM/Builtin/PharRunning.php');
        self::assertStringNotContainsString('PHPCompiler\\ext\\simplexml', $cast);
        self::assertStringNotContainsString('PHPCompiler\\ext\\phar', $phar);
        self::assertStringContainsString('SimpleXmlVmRuntimeSupport', $cast);
        self::assertStringContainsString('PharVmRuntimeSupport', $phar);
    }

    public function testPharRunningPathHook(): void
    {
        PharVmRuntimeSupport::clear();
        self::assertSame('', PharVmRuntimeSupport::runningPath('/app/tool.phar/index.php', false));

        PharVmRuntimeSupport::setRunningPath(
            static function (string $scriptPath, bool $retPhar): string {
                return VmPhar::runningPath($scriptPath, $retPhar);
            }
        );
        self::assertSame(
            '/app/tool.phar',
            PharVmRuntimeSupport::runningPath('/app/tool.phar/index.php', false)
        );
        self::assertSame('tool', PharVmRuntimeSupport::runningPath('/app/tool.phar/index.php', true));
        PharVmRuntimeSupport::clear();
    }

    public function testSimpleXmlHandlesUnsetWithoutHook(): void
    {
        SimpleXmlVmRuntimeSupport::clear();
        $class = new \PHPCompiler\VM\ClassEntry('SimpleXMLElement');
        $obj = new \PHPCompiler\VM\ObjectEntry($class);
        self::assertFalse(SimpleXmlVmRuntimeSupport::handles($obj));
        self::assertNull(SimpleXmlVmRuntimeSupport::exportZendArrayCast($obj));
    }

    public function testCastSupportAndPharRunningClassLoad(): void
    {
        self::assertTrue(class_exists(CastSupport::class));
        self::assertTrue(class_exists(PharRunning::class));
        self::assertTrue(class_exists(SimpleXmlJsonExport::class));
    }
}
