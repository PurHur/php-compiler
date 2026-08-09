<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\apcu\ApcuConstants;
use PHPCompiler\ext\apcu\ApcuExtensionPolicy;
use PHPCompiler\ext\brotli\BrotliConstants;
use PHPCompiler\ext\brotli\BrotliExtensionPolicy;
use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\curl\CurlConstants;
use PHPCompiler\ext\dom\DomConstants;
use PHPCompiler\ext\dom\DomExceptionConstants;
use PHPCompiler\ext\dom\DomLivingConstants;
use PHPCompiler\ext\eio\EioConstants;
use PHPCompiler\ext\eio\EioExtensionPolicy;
use PHPCompiler\ext\exif\ExifConstants;
use PHPCompiler\ext\fileinfo\FileinfoConstants;
use PHPCompiler\ext\filter\FilterConstants;
use PHPCompiler\ext\ftp\FtpConstants;
use PHPCompiler\ext\gd\GdConstants;
use PHPCompiler\ext\gd\GdExtensionPolicy;
use PHPCompiler\ext\gmp\GmpConstants;
use PHPCompiler\ext\hash\MhashRegistry;
use PHPCompiler\ext\hash\VmHashContext;
use PHPCompiler\ext\iconv\IconvConstants;
use PHPCompiler\ext\imap\ImapConstants;
use PHPCompiler\ext\imap\ImapExtensionPolicy;
use PHPCompiler\ext\inotify\InotifyConstants;
use PHPCompiler\ext\intl\IntlConstants;
use PHPCompiler\ext\ldap\LdapConstants;
use PHPCompiler\ext\ldap\LdapExtensionPolicy;
use PHPCompiler\ext\libxml\LibxmlConstants;
use PHPCompiler\ext\mbstring\MbstringConstants;
use PHPCompiler\ext\msgpack\MsgpackConstants;
use PHPCompiler\ext\msgpack\MsgpackExtensionPolicy;
use PHPCompiler\ext\mysqli\MysqliConstants;
use PHPCompiler\ext\odbc\OdbcConstants;
use PHPCompiler\ext\openssl\OpensslConstants;
use PHPCompiler\ext\pcntl\PcntlConstants;
use PHPCompiler\ext\pgsql\PgsqlConstants;
use PHPCompiler\ext\posix\PosixConstants;
use PHPCompiler\ext\pspell\PspellConstants;
use PHPCompiler\ext\random\RandomConstants;
use PHPCompiler\ext\session\SessionConstants;
use PHPCompiler\ext\snmp\SnmpConstants;
use PHPCompiler\ext\snmp\SnmpExtensionPolicy;
use PHPCompiler\ext\sqlite3\Sqlite3Constants;
use PHPCompiler\ext\soap\SoapConstants;
use PHPCompiler\ext\sockets\SocketConstants;
use PHPCompiler\ext\sodium\SodiumConstants;
use PHPCompiler\ext\ssh2\Ssh2Constants;
use PHPCompiler\ext\ssh2\Ssh2ExtensionPolicy;
use PHPCompiler\ext\sysvmsg\SysvmsgConstants;
use PHPCompiler\ext\tidy\TidyConstants;
use PHPCompiler\ext\tokenizer\TokenConstants;
use PHPCompiler\ext\uuid\UuidConstants;
use PHPCompiler\ext\xml\XmlConstants;
use PHPCompiler\ext\yaml\YamlConstants;
use PHPCompiler\ext\yaml\YamlExtensionPolicy;

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
        $groups['filter'] = FilterConstants::registeredConstants();
        $groups['tokenizer'] = TokenConstants::registeredConstants();
        $groups['dom'] = array_merge(
            DomExceptionConstants::globalConstants(),
            DomConstants::globalConstants(),
            // Dom\HTML_NO_DEFAULT_NS — living stub const; Zend 8.4+ dom bucket (#29485 / #26008).
            DomLivingConstants::registeredConstants()
        );
        $groups['libxml'] = LibxmlConstants::registeredConstants();
        $groups['openssl'] = OpensslConstants::registeredConstants();
        $groups['pcntl'] = PcntlConstants::registeredConstants();
        $groups['posix'] = PosixConstants::registeredConstants();
        $groups['session'] = SessionConstants::registeredConstants();
        $groups['mbstring'] = MbstringConstants::registeredConstants();
        $groups['iconv'] = IconvConstants::registeredConstants();
        $groups['hash'] = array_merge(
            MhashRegistry::constants(),
            ['HASH_HMAC' => VmHashContext::HASH_HMAC]
        );
        if (\PHPCompiler\ext\inotify\InotifyExtensionPolicy::advertisesExtension()) {
            $groups['inotify'] = InotifyConstants::registeredConstants();
        }
        $groups['curl'] = CurlConstants::registeredConstants();
        // intl bucket only when host php-intl advertises — no empty/phantom GRAPHEME_EXTR_* (#24128).
        if (\PHPCompiler\ext\intl\IntlExtensionPolicy::advertisesExtension()) {
            $groups['intl'] = IntlConstants::registeredConstants();
        }
        if (LdapExtensionPolicy::advertisesExtension()) {
            $groups['ldap'] = LdapConstants::registeredConstants();
        }
        $groups['random'] = RandomConstants::registeredConstants();
        if (\PHPCompiler\ext\uuid\UuidExtensionPolicy::advertisesExtension()) {
            $groups['uuid'] = UuidConstants::registeredConstants();
        }
        $groups['xml'] = XmlConstants::registeredConstants();
        $groups['sockets'] = SocketConstants::registeredConstants();
        $groups['fileinfo'] = FileinfoConstants::registeredConstants();
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
        // Module buckets for extensions that register into Context::$constants (#22337 / re-#19113 / #22858).
        $groups['ftp'] = FtpConstants::registeredConstants();
        $groups['mysqli'] = MysqliConstants::registeredConstants();
        $groups['sqlite3'] = Sqlite3Constants::globalConstants();
        // exif registers EXIF_USE_MBSTRING at MINIT (php-src ext/exif/exif.c). Without a group here
        // it lands in the 'user' bucket, which must stay empty when the script calls no define().
        $groups['exif'] = ExifConstants::registeredConstants();
        if (SnmpExtensionPolicy::advertisesExtension()) {
            $groups['snmp'] = SnmpConstants::registeredConstants();
        }
        if (\PHPCompiler\ext\gmp\GmpExtensionPolicy::advertisesExtension()) {
            $groups['gmp'] = GmpConstants::registeredConstants();
        }
        if (\PHPCompiler\ext\soap\SoapExtensionPolicy::advertisesExtension()) {
            $groups['soap'] = SoapConstants::registeredConstants();
        }
        $groups['tidy'] = TidyConstants::registeredConstants();
        if (\PHPCompiler\ext\pgsql\PgsqlExtensionPolicy::advertisesExtension()) {
            $groups['pgsql'] = PgsqlConstants::registeredConstants();
        }
        if (\PHPCompiler\ext\pspell\PspellExtensionPolicy::advertisesExtension()) {
            $groups['pspell'] = PspellConstants::registeredConstants();
        }
        $groups['odbc'] = OdbcConstants::registeredConstants();
        $groups['sysvmsg'] = SysvmsgConstants::registeredConstants();
        // PECL / optional modules that register into Context::$constants (#29485 / re-#23732).
        // Without a group here their MINIT names land in 'user', which Zend omits when empty.
        if (ApcuExtensionPolicy::advertisesExtension()) {
            $groups['apcu'] = ApcuConstants::registeredConstants();
        }
        if (YamlExtensionPolicy::advertisesExtension()) {
            $groups['yaml'] = YamlConstants::registeredConstants();
        }
        if (MsgpackExtensionPolicy::advertisesExtension()) {
            $groups['msgpack'] = MsgpackConstants::registeredConstants();
        }
        if (BrotliExtensionPolicy::advertisesExtension()) {
            $groups['brotli'] = BrotliConstants::registeredConstants();
        }
        if (Ssh2ExtensionPolicy::advertisesExtension()) {
            $groups['ssh2'] = Ssh2Constants::registeredConstants();
        }
        if (EioExtensionPolicy::advertisesExtension()) {
            $groups['eio'] = EioConstants::registeredConstants();
        }
        if (ImapExtensionPolicy::advertisesExtension()) {
            $groups['imap'] = ImapConstants::registeredConstants();
        }

        return $groups;
    }

    /**
     * Whether get_defined_constants(true) should emit a module bucket (#18083).
     *
     * Socket constants register with the sockets extension once socket_create() lands (#11820, #19286).
     * Keep materializing the sockets bucket when the extension is withheld on older profiles.
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

    /**
     * Extension bucket for a non-user constant (ReflectionConstant::getExtension*, #21551).
     * Returns null when the name is not a known extension/Core constant.
     */
    public static function extensionNameForConstant(string $name): ?string
    {
        if (isset(self::CATEGORIZED_CORE_RUNTIME_NAMES[$name])) {
            return 'Core';
        }
        foreach (StdlibModuleConstants::CORE_BUCKET_NAMES as $coreName) {
            if ($coreName === $name) {
                return 'Core';
            }
        }
        foreach (self::groups() as $extension => $constants) {
            if (isset($constants[$name])) {
                return $extension;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function coreBucketNames(): array
    {
        return StdlibModuleConstants::CORE_BUCKET_NAMES;
    }
}
