<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\curl\CurlConstants;
use PHPCompiler\ext\dom\DomExceptionConstants;
use PHPCompiler\ext\filter\FilterConstants;
use PHPCompiler\ext\gd\GdConstants;
use PHPCompiler\ext\gd\GdExtensionPolicy;
use PHPCompiler\ext\hash\MhashRegistry;
use PHPCompiler\ext\iconv\IconvConstants;
use PHPCompiler\ext\inotify\InotifyConstants;
use PHPCompiler\ext\intl\IntlConstants;
use PHPCompiler\ext\ldap\LdapConstants;
use PHPCompiler\ext\ldap\LdapExtensionPolicy;
use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\ext\mbstring\MbstringConstants;
use PHPCompiler\ext\openssl\OpensslConstants;
use PHPCompiler\ext\pcntl\PcntlConstants;
use PHPCompiler\ext\posix\PosixConstants;
use PHPCompiler\ext\random\RandomConstants;
use PHPCompiler\ext\session\SessionConstants;
use PHPCompiler\ext\sockets\SocketConstants;
use PHPCompiler\ext\sodium\SodiumConstants;
use PHPCompiler\ext\tokenizer\TokenConstants;
use PHPCompiler\ext\uuid\UuidConstants;
use PHPCompiler\ext\xml\XmlConstants;

/**
 * Extension constant groups for get_defined_constants(true) (Zend/zend_builtin_functions.c; #17416, #17799).
 */
final class ExtensionConstantGroups
{
    /** CLI stdio constants registered at runtime — Zend Core bucket, not user (#4840). */
    private const CATEGORIZED_CORE_RUNTIME_NAMES = [
        'STDIN' => true,
        'STDOUT' => true,
        'STDERR' => true,
    ];

    /** @var array<string, true>|null */
    private static ?array $registeredNameSet = null;

    /**
     * Extension name => constant name => fallback scalar for bucket materialization.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function groups(): array
    {
        $groups = StdlibModuleConstants::categorizedBootstrapConstants();
        $groups['calendar'] = CalendarConstants::registeredConstants();
        $groups['filter'] = FilterConstants::REGISTERED;
        $groups['tokenizer'] = TokenConstants::registeredConstants();
        $groups['dom'] = DomExceptionConstants::globalConstants();
        $groups['libxml'] = LibxmlConstants::registeredConstants();
        $groups['openssl'] = OpensslConstants::registeredConstants();
        $groups['pcntl'] = PcntlConstants::registeredConstants();
        $groups['posix'] = PosixConstants::registeredConstants();
        $groups['session'] = SessionConstants::registeredConstants();
        $groups['mbstring'] = MbstringConstants::registeredConstants();
        $groups['iconv'] = IconvConstants::registeredConstants();
        $groups['hash'] = MhashRegistry::constants();
        if (\PHPCompiler\ext\inotify\InotifyExtensionPolicy::advertisesExtension()) {
            $groups['inotify'] = InotifyConstants::registeredConstants();
        }
        $groups['curl'] = CurlConstants::registeredConstants();
        $groups['intl'] = IntlConstants::registeredConstants();
        if (LdapExtensionPolicy::advertisesExtension()) {
            $groups['ldap'] = LdapConstants::registeredConstants();
        }
        $groups['random'] = RandomConstants::registeredConstants();
        $groups['uuid'] = UuidConstants::registeredConstants();
        $groups['xml'] = XmlConstants::registeredConstants();
        $groups['sockets'] = SocketConstants::registeredConstants();
        if (GdExtensionPolicy::advertisesDrawing()) {
            $groups['gd'] = GdConstants::REGISTERED;
        }
        $groups['readline'] = ReadlineConstants::registeredConstants();
        if (\PHPCompiler\ext\xsl\XslExtensionPolicy::advertisesExtension()) {
            $groups['xsl'] = XslConstants::registeredConstants();
        }
        if (\PHPCompiler\ext\sodium\SodiumExtensionPolicy::advertisesExtension()) {
            $groups['sodium'] = SodiumConstants::registeredConstants();
        }

        return $groups;
    }

    /**
     * Whether get_defined_constants(true) should emit a module bucket (#18083).
     *
     * Socket constants register on the reference profile while extension_loaded('sockets')
     * stays false until socket_create() lands (#11820).
     */
    public static function shouldMaterializeExtensionBucket(string $extension): bool
    {
        if (VmInfo::extension_loaded($extension)) {
            return true;
        }

        return 'sockets' === strtolower($extension);
    }

    /** @return array<string, true> */
    public static function registeredNameSet(): array
    {
        if (null !== self::$registeredNameSet) {
            return self::$registeredNameSet;
        }
        $set = [];
        foreach (self::groups() as $constants) {
            foreach (array_keys($constants) as $name) {
                $set[$name] = true;
            }
        }
        foreach (StdlibModuleConstants::CORE_BUCKET_NAMES as $name) {
            $set[$name] = true;
        }
        foreach (LdapConstants::registeredConstants() as $name => $_) {
            $set[$name] = true;
        }
        foreach (GetDefinedConstantsParity::standardBucketExcludeNames() as $name) {
            $set[$name] = true;
        }
        self::$registeredNameSet = $set;

        return self::$registeredNameSet;
    }

    public static function isExtensionConstantName(string $name): bool
    {
        return isset(self::registeredNameSet()[$name])
            || isset(self::CATEGORIZED_CORE_RUNTIME_NAMES[$name]);
    }

    /** @return list<string> */
    public static function coreBucketNames(): array
    {
        return StdlibModuleConstants::CORE_BUCKET_NAMES;
    }
}
