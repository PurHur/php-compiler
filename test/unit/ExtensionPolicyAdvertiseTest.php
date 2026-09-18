<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ExtensionRegistry;
use PHPCompiler\ext\apcu\ApcuExtensionPolicy;
use PHPCompiler\ext\brotli\BrotliExtensionPolicy;
use PHPCompiler\ext\bz2\Bz2ExtensionPolicy;
use PHPCompiler\ext\curl\CurlExtensionPolicy;
use PHPCompiler\ext\dba\DbaExtensionPolicy;
use PHPCompiler\ext\ds\DsExtensionPolicy;
use PHPCompiler\ext\eio\EioExtensionPolicy;
use PHPCompiler\ext\enchant\EnchantExtensionPolicy;
use PHPCompiler\ext\ffi\FfiExtensionPolicy;
use PHPCompiler\ext\ftp\FtpExtensionPolicy;
use PHPCompiler\ext\gd\GdExtensionPolicy;
use PHPCompiler\ext\gmp\GmpExtensionPolicy;
use PHPCompiler\ext\igbinary\IgbinaryExtensionPolicy;
use PHPCompiler\ext\imagick\ImagickExtensionPolicy;
use PHPCompiler\ext\inotify\InotifyExtensionPolicy;
use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\ldap\LdapExtensionPolicy;
use PHPCompiler\ext\oci8\Oci8ExtensionPolicy;
use PHPCompiler\ext\openssl\OpensslExtensionPolicy;
use PHPCompiler\ext\pdo\PdoExtensionPolicy;
use PHPCompiler\ext\pgsql\PgsqlExtensionPolicy;
use PHPCompiler\ext\phar\PharExtensionPolicy;
use PHPCompiler\ext\pspell\PspellExtensionPolicy;
use PHPCompiler\ext\rar\RarExtensionPolicy;
use PHPCompiler\ext\redis\RedisExtensionPolicy;
use PHPCompiler\ext\soap\SoapExtensionPolicy;
use PHPCompiler\ext\sodium\SodiumExtensionPolicy;
use PHPCompiler\ext\sodium\VmSodium;
use PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy;
use PHPCompiler\ext\sqlsrv\SqlsrvExtensionPolicy;
use PHPCompiler\ext\tidy\TidyExtensionPolicy;
use PHPCompiler\ext\uuid\UuidExtensionPolicy;
use PHPCompiler\ext\xsl\XslExtensionPolicy;
use PHPCompiler\ext\zip\ZipExtensionPolicy;
use PHPCompiler\ext\zstd\ZstdExtensionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Manifest-driven advertise fold (#36204).
 */
final class ExtensionPolicyAdvertiseTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_ENABLE_REDIS');
        unset($_ENV['PHP_COMPILER_ENABLE_REDIS'], $_SERVER['PHP_COMPILER_ENABLE_REDIS']);
        putenv('PHP_COMPILER_ENABLE_RAR');
        unset($_ENV['PHP_COMPILER_ENABLE_RAR'], $_SERVER['PHP_COMPILER_ENABLE_RAR']);
        putenv('PHP_COMPILER_ENABLE_EIO');
        unset($_ENV['PHP_COMPILER_ENABLE_EIO'], $_SERVER['PHP_COMPILER_ENABLE_EIO']);
        putenv('PHP_COMPILER_ENABLE_CURL');
        unset($_ENV['PHP_COMPILER_ENABLE_CURL'], $_SERVER['PHP_COMPILER_ENABLE_CURL']);
        putenv('PHP_COMPILER_ENABLE_DBA');
        unset($_ENV['PHP_COMPILER_ENABLE_DBA'], $_SERVER['PHP_COMPILER_ENABLE_DBA']);
        putenv('PHP_COMPILER_ENABLE_ZIP');
        unset($_ENV['PHP_COMPILER_ENABLE_ZIP'], $_SERVER['PHP_COMPILER_ENABLE_ZIP']);
        putenv('PHP_COMPILER_ENABLE_ZSTD');
        unset($_ENV['PHP_COMPILER_ENABLE_ZSTD'], $_SERVER['PHP_COMPILER_ENABLE_ZSTD']);
        putenv('PHP_COMPILER_ENABLE_BZ2');
        unset($_ENV['PHP_COMPILER_ENABLE_BZ2'], $_SERVER['PHP_COMPILER_ENABLE_BZ2']);
        putenv('PHP_COMPILER_ENABLE_ENCHANT');
        unset($_ENV['PHP_COMPILER_ENABLE_ENCHANT'], $_SERVER['PHP_COMPILER_ENABLE_ENCHANT']);
        putenv('PHP_COMPILER_ENABLE_IMAGICK');
        unset($_ENV['PHP_COMPILER_ENABLE_IMAGICK'], $_SERVER['PHP_COMPILER_ENABLE_IMAGICK']);
        putenv('PHP_COMPILER_ENABLE_LDAP');
        unset($_ENV['PHP_COMPILER_ENABLE_LDAP'], $_SERVER['PHP_COMPILER_ENABLE_LDAP']);
        putenv('PHP_COMPILER_ENABLE_PGSQL');
        unset($_ENV['PHP_COMPILER_ENABLE_PGSQL'], $_SERVER['PHP_COMPILER_ENABLE_PGSQL']);
        putenv('PHP_COMPILER_ENABLE_PSPELL');
        unset($_ENV['PHP_COMPILER_ENABLE_PSPELL'], $_SERVER['PHP_COMPILER_ENABLE_PSPELL']);
        putenv('PHP_COMPILER_ENABLE_GMP');
        unset($_ENV['PHP_COMPILER_ENABLE_GMP'], $_SERVER['PHP_COMPILER_ENABLE_GMP']);
        putenv('PHP_COMPILER_ENABLE_INTL');
        unset($_ENV['PHP_COMPILER_ENABLE_INTL'], $_SERVER['PHP_COMPILER_ENABLE_INTL']);
        parent::tearDown();
    }

    public function testPharAlwaysAdvertises(): void
    {
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('phar'));
        self::assertTrue(PharExtensionPolicy::advertisesExtension());
    }

    public function testPdoOci8SqlsrvAlwaysAdvertise(): void
    {
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('pdo'));
        self::assertTrue(PdoExtensionPolicy::advertisesExtension());
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('oci8'));
        self::assertTrue(Oci8ExtensionPolicy::advertisesExtension());
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('sqlsrv'));
        self::assertTrue(SqlsrvExtensionPolicy::advertisesExtension());
    }

    public function testSqlite3HostOrCompilerVersion(): void
    {
        $expected = \extension_loaded('sqlite3') || CompilerVersion::supportsSqlite3();
        self::assertSame($expected, ExtensionRegistry::advertisesExtensionFor('sqlite3'));
        self::assertSame($expected, Sqlite3ExtensionPolicy::advertisesExtension());
        self::assertSame($expected, Sqlite3ExtensionPolicy::advertisesExtensionLoaded());
    }

    public function testGdAndSoapHostOnly(): void
    {
        self::assertSame(
            \extension_loaded('gd'),
            ExtensionRegistry::advertisesExtensionFor('gd')
        );
        self::assertSame(\extension_loaded('gd'), GdExtensionPolicy::advertisesExtension());
        self::assertSame(
            \extension_loaded('soap'),
            ExtensionRegistry::advertisesExtensionFor('soap')
        );
        self::assertSame(\extension_loaded('soap'), SoapExtensionPolicy::advertisesExtension());
    }

    public function testIgbinaryMatchesCompilerVersion(): void
    {
        self::assertSame(
            CompilerVersion::supportsIgbinary(),
            ExtensionRegistry::advertisesExtensionFor('igbinary')
        );
        self::assertSame(
            CompilerVersion::supportsIgbinary(),
            IgbinaryExtensionPolicy::advertisesExtension()
        );
    }

    public function testApcuHostOrCompilerVersion(): void
    {
        $expected = \extension_loaded('apcu') || CompilerVersion::supportsApcu();
        self::assertSame($expected, ExtensionRegistry::advertisesExtensionFor('apcu'));
        self::assertSame($expected, ApcuExtensionPolicy::advertisesExtension());
    }

    public function testRedisEnvOptIn(): void
    {
        putenv('PHP_COMPILER_ENABLE_REDIS=1');
        $_ENV['PHP_COMPILER_ENABLE_REDIS'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('redis'));
        self::assertTrue(RedisExtensionPolicy::advertisesExtension());
    }

    public function testUuidHostOrCompilerVersion(): void
    {
        $expected = \extension_loaded('uuid') || CompilerVersion::supportsUuid();
        self::assertSame($expected, ExtensionRegistry::advertisesExtensionFor('uuid'));
        self::assertSame($expected, UuidExtensionPolicy::advertisesExtension());
    }

    public function testRarEnvOptIn(): void
    {
        putenv('PHP_COMPILER_ENABLE_RAR=1');
        $_ENV['PHP_COMPILER_ENABLE_RAR'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('rar'));
        self::assertTrue(RarExtensionPolicy::advertisesExtension());
    }

    public function testEioEnvOptIn(): void
    {
        putenv('PHP_COMPILER_ENABLE_EIO=1');
        $_ENV['PHP_COMPILER_ENABLE_EIO'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('eio'));
        self::assertTrue(EioExtensionPolicy::advertisesExtension());
    }

    public function testCurlDbaHostOrEnv(): void
    {
        $curlHost = \extension_loaded('curl');
        self::assertSame($curlHost, ExtensionRegistry::advertisesExtensionFor('curl'));
        self::assertSame($curlHost, CurlExtensionPolicy::advertisesExtension());
        $dbaHost = \extension_loaded('dba');
        self::assertSame($dbaHost, ExtensionRegistry::advertisesExtensionFor('dba'));
        self::assertSame($dbaHost, DbaExtensionPolicy::advertisesExtension());
        $dsHost = \extension_loaded('ds');
        self::assertSame($dsHost, ExtensionRegistry::advertisesExtensionFor('ds'));
        self::assertSame($dsHost, DsExtensionPolicy::advertisesExtension());

        putenv('PHP_COMPILER_ENABLE_CURL=1');
        $_ENV['PHP_COMPILER_ENABLE_CURL'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('curl'));
        self::assertTrue(CurlExtensionPolicy::advertisesExtension());
        putenv('PHP_COMPILER_ENABLE_DBA=1');
        $_ENV['PHP_COMPILER_ENABLE_DBA'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('dba'));
        self::assertTrue(DbaExtensionPolicy::advertisesExtension());
    }

    public function testZipZstdHostOrEnv(): void
    {
        $zipHost = \extension_loaded('zip');
        self::assertSame($zipHost, ExtensionRegistry::advertisesExtensionFor('zip'));
        self::assertSame($zipHost, ZipExtensionPolicy::advertisesExtension());
        $zstdHost = \extension_loaded('zstd');
        self::assertSame($zstdHost, ExtensionRegistry::advertisesExtensionFor('zstd'));
        self::assertSame($zstdHost, ZstdExtensionPolicy::advertisesExtension());

        putenv('PHP_COMPILER_ENABLE_ZIP=1');
        $_ENV['PHP_COMPILER_ENABLE_ZIP'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('zip'));
        self::assertTrue(ZipExtensionPolicy::advertisesExtension());
        putenv('PHP_COMPILER_ENABLE_ZSTD=1');
        $_ENV['PHP_COMPILER_ENABLE_ZSTD'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('zstd'));
        self::assertTrue(ZstdExtensionPolicy::advertisesExtension());
    }

    public function testBz2LdapPgsqlHostOrEnv(): void
    {
        foreach ([
            'bz2' => Bz2ExtensionPolicy::class,
            'enchant' => EnchantExtensionPolicy::class,
            'imagick' => ImagickExtensionPolicy::class,
            'ldap' => LdapExtensionPolicy::class,
            'pgsql' => PgsqlExtensionPolicy::class,
            'pspell' => PspellExtensionPolicy::class,
        ] as $ext => $policy) {
            $host = \extension_loaded($ext);
            self::assertSame($host, ExtensionRegistry::advertisesExtensionFor($ext));
            self::assertSame($host, $policy::advertisesExtension());
        }

        putenv('PHP_COMPILER_ENABLE_BZ2=1');
        $_ENV['PHP_COMPILER_ENABLE_BZ2'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('bz2'));
        self::assertTrue(Bz2ExtensionPolicy::advertisesExtension());
        putenv('PHP_COMPILER_ENABLE_LDAP=1');
        $_ENV['PHP_COMPILER_ENABLE_LDAP'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('ldap'));
        self::assertTrue(LdapExtensionPolicy::advertisesExtension());
        putenv('PHP_COMPILER_ENABLE_PGSQL=1');
        $_ENV['PHP_COMPILER_ENABLE_PGSQL'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('pgsql'));
        self::assertTrue(PgsqlExtensionPolicy::advertisesExtension());
    }

    public function testBrotliFtpCompilerVersion(): void
    {
        self::assertSame(
            CompilerVersion::supportsBrotli(),
            ExtensionRegistry::advertisesExtensionFor('brotli')
        );
        self::assertSame(
            CompilerVersion::supportsBrotli(),
            BrotliExtensionPolicy::advertisesExtension()
        );
        self::assertSame(
            CompilerVersion::supportsFtpConnection(),
            ExtensionRegistry::advertisesExtensionFor('ftp')
        );
        self::assertSame(
            CompilerVersion::supportsFtpConnection(),
            FtpExtensionPolicy::advertisesExtension()
        );
    }

    public function testFfiTidyXslHostOnly(): void
    {
        self::assertSame(\extension_loaded('ffi'), ExtensionRegistry::advertisesExtensionFor('ffi'));
        self::assertSame(\extension_loaded('ffi'), FfiExtensionPolicy::advertisesExtension());
        self::assertSame(\extension_loaded('tidy'), ExtensionRegistry::advertisesExtensionFor('tidy'));
        self::assertSame(\extension_loaded('tidy'), TidyExtensionPolicy::advertisesExtension());
        self::assertSame(\extension_loaded('xsl'), ExtensionRegistry::advertisesExtensionFor('xsl'));
        self::assertSame(\extension_loaded('xsl'), XslExtensionPolicy::advertisesExtension());
    }

    public function testOpensslAlwaysAndInotifyNever(): void
    {
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('openssl'));
        self::assertTrue(OpensslExtensionPolicy::advertisesExtension());
        self::assertFalse(ExtensionRegistry::advertisesExtensionFor('inotify'));
        self::assertFalse(InotifyExtensionPolicy::advertisesExtension());
    }

    public function testSodiumProbeMatchesVmSodiumAvailable(): void
    {
        $expected = VmSodium::available();
        self::assertSame($expected, ExtensionRegistry::advertisesExtensionFor('sodium'));
        self::assertSame($expected, SodiumExtensionPolicy::advertisesExtension());
    }

    public function testGmpEnvOnlyAndIntlRequireEnvAndHost(): void
    {
        putenv('PHP_COMPILER_ENABLE_GMP');
        unset($_ENV['PHP_COMPILER_ENABLE_GMP']);
        self::assertFalse(ExtensionRegistry::advertisesExtensionFor('gmp'));
        self::assertFalse(GmpExtensionPolicy::advertisesExtension());
        putenv('PHP_COMPILER_ENABLE_GMP=1');
        $_ENV['PHP_COMPILER_ENABLE_GMP'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('gmp'));
        self::assertTrue(GmpExtensionPolicy::advertisesExtension());

        putenv('PHP_COMPILER_ENABLE_INTL');
        unset($_ENV['PHP_COMPILER_ENABLE_INTL']);
        self::assertFalse(ExtensionRegistry::advertisesExtensionFor('intl'));
        self::assertFalse(IntlExtensionPolicy::advertisesExtension());
        putenv('PHP_COMPILER_ENABLE_INTL=1');
        $_ENV['PHP_COMPILER_ENABLE_INTL'] = '1';
        $expected = \extension_loaded('intl');
        self::assertSame($expected, ExtensionRegistry::advertisesExtensionFor('intl'));
        self::assertSame($expected, IntlExtensionPolicy::advertisesExtension());
    }

    public function testUnknownAdvertiseThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExtensionRegistry::advertisesExtensionFor('standard');
    }

    public function testGeneratorCheckStaysCurrent(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/generate-extension-registry.php').' --check';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    public function testSyncPreservesAdvertise(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/sync-extension-manifests.php').' --check';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}
