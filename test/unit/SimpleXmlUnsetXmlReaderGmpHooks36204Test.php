<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\ReleaseUnsupportedExtensions;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\SimpleXmlVmRuntimeSupport;
use PHPUnit\Framework\TestCase;

/**
 * lib must not import ext\simplexml / ext\xmlreader / ext\gmp for unset / XMLReader this /
 * GMP compliance helpers (#36204).
 */
final class SimpleXmlUnsetXmlReaderGmpHooks36204Test extends TestCase
{
    protected function tearDown(): void
    {
        SimpleXmlVmRuntimeSupport::clear();
        parent::tearDown();
    }

    /** @return list<string> */
    private static function libPaths(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root.'/lib/VM/Concern/UnsetDispatch.php',
            $root.'/lib/VM/Concern/UserInvokeArrayAccessAndClosureCall.php',
            $root.'/lib/ReleaseUnsupportedExtensions.php',
        ];
    }

    public function testLibSurfacesHaveNoDirectExtImports(): void
    {
        foreach (self::libPaths() as $path) {
            $src = (string) file_get_contents($path);
            // Strip block + line comments so @see / "must not import" docs do not false-fail.
            $code = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
            $code = preg_replace('#//.*$#m', '', $code) ?? $code;
            self::assertDoesNotMatchRegularExpression(
                '/(?:^|[^A-Za-z0-9_\\\\])(?:\\\\?PHPCompiler\\\\)?ext\\\\(?:simplexml|xmlreader|gmp)\\\\/m',
                $code,
                basename($path).' must not import ext\\simplexml / ext\\xmlreader / ext\\gmp'
            );
        }
    }

    public function testUnsetDispatchUsesSimpleXmlVmRuntimeSupport(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/VM/Concern/UnsetDispatch.php');
        self::assertStringContainsString('SimpleXmlVmRuntimeSupport', $src);
        self::assertStringContainsString('isUnsetChildPropertySubject', $src);
        self::assertStringContainsString('unsetChildProperty', $src);
    }

    public function testXmlReaderThisGateUsesClassNameLiteral(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/Concern/UserInvokeArrayAccessAndClosureCall.php'
        );
        self::assertStringContainsString("'xmlreader'", $src);
        self::assertStringContainsString('staticMethodKeepsInstanceThis', $src);
    }

    public function testGmpComplianceHelpersLiveInLib(): void
    {
        self::assertTrue(ReleaseUnsupportedExtensions::isGmpComplianceCase('gmp/add_cmp'));
        self::assertTrue(ReleaseUnsupportedExtensions::isGmpComplianceCase('stdlib/extension_loaded_gmp'));
        self::assertTrue(
            ReleaseUnsupportedExtensions::isGmpPhantomComplianceCase('stdlib/extension_loaded_gmp_phantom')
        );
        self::assertFalse(ReleaseUnsupportedExtensions::isGmpComplianceCase('intl/collator'));

        $env = [];
        ReleaseUnsupportedExtensions::applyComplianceEnv('gmp/add_cmp', $env);
        self::assertSame('1', $env['PHP_COMPILER_ENABLE_GMP']);

        $env = [];
        ReleaseUnsupportedExtensions::applyComplianceEnv('stdlib/extension_loaded_gmp_phantom', $env);
        self::assertArrayNotHasKey('PHP_COMPILER_ENABLE_GMP', $env);
    }

    public function testUnsetChildPropertyHookRoundTrip(): void
    {
        SimpleXmlVmRuntimeSupport::clear();
        $class = new \PHPCompiler\VM\ClassEntry('SimpleXMLElement');
        $obj = new ObjectEntry($class);
        self::assertFalse(SimpleXmlVmRuntimeSupport::isUnsetChildPropertySubject($obj));

        $seen = [];
        SimpleXmlVmRuntimeSupport::setIsUnsetChildPropertySubject(
            static function (ObjectEntry $o) use ($obj): bool {
                return $o === $obj;
            }
        );
        SimpleXmlVmRuntimeSupport::setUnsetChildProperty(
            static function (ObjectEntry $o, string $name) use (&$seen): void {
                $seen[] = $name;
            }
        );
        self::assertTrue(SimpleXmlVmRuntimeSupport::isUnsetChildPropertySubject($obj));
        SimpleXmlVmRuntimeSupport::unsetChildProperty($obj, 'child');
        self::assertSame(['child'], $seen);
    }
}
