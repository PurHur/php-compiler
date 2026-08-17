<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ExtensionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Freezes the extension load order (RELEASE-PLAN Phase 2.5).
 *
 * lib/ExtensionRegistry.php is generated, and Runtime::loadCoreModules() now iterates it instead of
 * carrying 76 hardcoded `new ext\X\Module` calls. Ordering constraints here are real — libxml before
 * dom before xsl — and only some are declared on the modules themselves yet, so the safety argument
 * for that refactor is that the order did not change.
 *
 * This asserts exactly that against a literal list captured from the hardcoded loads. If a
 * regeneration or a hand edit reorders anything, this fails and names the position.
 */
final class ExtensionRegistryOrderTest extends TestCase
{
    /**
     * Identity is the ext/ DIRECTORY, not getExtensionName().
     *
     * 20 modules deliberately report getExtensionName() === 'standard' — zip, intl, gd, bcmath, bz2,
     * ftp, msgpack and others expose their functions as part of ext/standard in php-src terms. So the
     * reported name is not unique and cannot identify a registry entry; the class name can.
     *
     * @return list<string>
     */
    private static function directoryNames(): array
    {
        $names = [];
        foreach (ExtensionRegistry::defaultModules() as $module) {
            $class = \get_class($module);
            self::assertSame(
                1,
                preg_match('#\\\\ext\\\\([^\\\\]+)\\\\Module$#', $class, $m),
                'unexpected module class shape: '.$class
            );
            $names[] = $m[1];
        }

        return $names;
    }

    /** The order Runtime::loadCoreModules() used before it delegated to the registry. */
    private const EXPECTED_ORDER = [
        'types', 'spl', 'ds', 'intl', 'zip', 'libxml', 'dom', 'xsl', 'simplexml', 'xml',
        'xmlrpc', 'wddx', 'xmlreader', 'xmlwriter', 'gd', 'imagick', 'exif', 'fileinfo', 'iconv', 'gettext',
        'mbstring', 'filter', 'calendar', 'ldap', 'session', 'bcmath',
    ];

    public function testLoadOrderPrefixIsUnchanged(): void
    {
        $prefix = \array_slice(self::directoryNames(), 0, \count(self::EXPECTED_ORDER));
        self::assertSame(
            self::EXPECTED_ORDER,
            $prefix,
            'Extension load order changed. The order is load-bearing (libxml before dom before xsl) '
            .'and is only partly declared via Module::getExtensionDependencies(), so a reorder needs '
            .'to be justified rather than absorbed.'
        );
    }

    public function testEveryModuleIsDefaultEnabledAndUnique(): void
    {
        foreach (ExtensionRegistry::defaultModules() as $module) {
            self::assertTrue(
                $module->isDefaultEnabled(),
                \get_class($module).' is in the default registry but reports isDefaultEnabled() false'
            );
        }
        $names = self::directoryNames();

        self::assertSame(
            \array_unique($names),
            $names,
            'the same extension is registered twice: '.implode(', ', array_diff_assoc($names, \array_unique($names)))
        );
        self::assertGreaterThan(70, \count($names), 'registry looks truncated');
    }
}
