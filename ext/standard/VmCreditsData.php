<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/**
 * php-src credit tables for phpcredits() (ext/standard/info.c, #3359, #13618).
 *
 * Static attribution data only — rendering lives in {@see VmInfo}.
 */
final class VmCreditsData
{
    /** php-src PHP_CREDITS_QA — Quality Assurance team listing. */
    public const QA_TEAM =
        'Ilia Alshanetsky, Joerg Behrens, Antony Dovgal, Stefan Esser, Moriyoshi Koizumi, '
        .'Magnus Maatta, Sebastian Nohn, Derick Rethans, Melvyn Sopacua, Pierre-Alain Joye, '
        .'Dmitry Stogov, Felipe Pena, David Soria Parra, Stanislav Malyshev, Julien Pauli, '
        .'Stephen Zarkos, Anatol Belski, Remi Collet, Ferenc Kovacs';

    /** php-src PHP_CREDITS_GROUP — PHP Group founders (ext/standard/credits.c). */
    public const PHP_GROUP =
        'Thies C. Arntzen, Stig Bakken, Shane Caraveo, Andi Gutmans, Rasmus Lerdorf, '
        .'Sam Ruby, Sascha Schumann, Zeev Suraski, Jim Winstead, Andrei Zmievski';

    /**
     * php-src PHP Authors rows under CREDITS_GENERAL (ext/standard/credits.c).
     *
     * @var array<string, string>
     */
    public const PHP_AUTHORS = [
        'Zend Scripting Language Engine' => 'Andi Gutmans, Zeev Suraski, Stanislav Malyshev, Marcus Boerger, Dmitry Stogov, Xinchen Hui, Nikita Popov',
        'Extension Module API' => 'Andi Gutmans, Zeev Suraski, Andrei Zmievski',
        'UNIX Build and Modularization' => 'Stig Bakken, Sascha Schumann, Jani Taskinen, Peter Kokot',
        'Windows Support' => 'Shane Caraveo, Zeev Suraski, Wez Furlong, Pierre-Alain Joye, Anatol Belski, Kalle Sommer Nielsen',
        'Server API (SAPI) Abstraction Layer' => 'Andi Gutmans, Shane Caraveo, Zeev Suraski',
        'Streams Abstraction Layer' => 'Wez Furlong, Sara Golemon',
        'PHP Data Objects Layer' => 'Wez Furlong, Marcus Boerger, Sterling Hughes, George Schlossnagle, Ilia Alshanetsky',
        'Output Handler' => 'Zeev Suraski, Thies C. Arntzen, Marcus Boerger, Michael Wallner',
        'Consistent 64 bit support' => 'Anthony Ferrara, Anatol Belski',
    ];

    /**
     * php-src credits_modules[] static table (ext/standard/info.c, #14295).
     *
     * @var array<string, string>
     */
    public const CREDITS_MODULE_AUTHORS = [
        'BC Math' => 'Andi Gutmans',
        'Bzip2' => 'Sterling Hughes',
        'Calendar' => 'Shane Caraveo, Colin Viebrock, Hartmut Holzgraefe, Wez Furlong',
        'COM and .Net' => 'Wez Furlong',
        'ctype' => 'Hartmut Holzgraefe',
        'cURL' => 'Sterling Hughes',
        'Date/Time Support' => 'Derick Rethans',
        'DB-LIB (MS SQL, Sybase)' => 'Wez Furlong, Frank M. Kromann, Adam Baratz',
        'DBA' => 'Sascha Schumann, Marcus Boerger',
        'DOM' => 'Christian Stocker, Rob Richards, Marcus Boerger, Nora Dossche',
        'enchant' => 'Pierre-Alain Joye, Ilia Alshanetsky',
        'EXIF' => 'Rasmus Lerdorf, Marcus Boerger',
        'FFI' => 'Dmitry Stogov',
        'fileinfo' => 'Ilia Alshanetsky, Pierre Alain Joye, Scott MacVicar, Derick Rethans, Anatol Belski',
        'Firebird driver for PDO' => 'Ard Biesheuvel',
        'FTP' => 'Stefan Esser, Andrew Skalski',
        'GD imaging' => 'Rasmus Lerdorf, Stig Bakken, Jim Winstead, Jouni Ahto, Ilia Alshanetsky, Pierre-Alain Joye, Marcus Boerger, Mark Randall',
        'GetText' => 'Alex Plotnick',
        'GNU GMP support' => 'Stanislav Malyshev',
        'Iconv' => 'Rui Hirokawa, Stig Bakken, Moriyoshi Koizumi',
        'IMAP' => 'Rex Logan, Mark Musone, Brian Wang, Kaj-Michael Lang, Antoni Pamies Olive, Rasmus Lerdorf, Andrew Skalski, Chuck Hagenbuch, Daniel R Kalowsky',
        'Input Filter' => 'Rasmus Lerdorf, Derick Rethans, Pierre-Alain Joye, Ilia Alshanetsky',
        'Internationalization' => 'Ed Batutis, Vladimir Iordanov, Dmitry Lakhtyuk, Stanislav Malyshev, Vadim Savchuk, Kirti Velankar',
        'JSON' => 'Jakub Zelenka, Omar Kilani, Scott MacVicar',
        'LDAP' => 'Amitay Isaacs, Eric Warnke, Rasmus Lerdorf, Gerrit Thomson, Stig Venaas',
        'LIBXML' => 'Christian Stocker, Rob Richards, Marcus Boerger, Wez Furlong, Shane Caraveo',
        'Multibyte String Functions' => 'Tsukada Takuya, Rui Hirokawa',
        'MySQL driver for PDO' => 'George Schlossnagle, Wez Furlong, Ilia Alshanetsky, Johannes Schlueter',
        'MySQLi' => 'Zak Greant, Georg Richter, Andrey Hristov, Ulf Wendel',
        'MySQLnd' => 'Andrey Hristov, Ulf Wendel, Georg Richter, Johannes Schlüter',
        'OCI8' => 'Stig Bakken, Thies C. Arntzen, Andy Sautins, David Benson, Maxim Maletsky, Harald Radi, Antony Dovgal, Andi Gutmans, Wez Furlong, Christopher Jones, Oracle Corporation',
        'ODBC driver for PDO' => 'Wez Furlong',
        'ODBC' => 'Stig Bakken, Andreas Karajannis, Frank M. Kromann, Daniel R. Kalowsky',
        'Opcache' => 'Andi Gutmans, Zeev Suraski, Stanislav Malyshev, Dmitry Stogov, Xinchen Hui',
        'OpenSSL' => 'Stig Venaas, Wez Furlong, Sascha Kettler, Scott MacVicar, Eliot Lear',
        'Oracle (OCI) driver for PDO' => 'Wez Furlong',
        'pcntl' => 'Jason Greene, Arnaud Le Blanc',
        'Perl Compatible Regexps' => 'Andrei Zmievski',
        'PHP Archive' => 'Gregory Beaver, Marcus Boerger',
        'PHP Data Objects' => 'Wez Furlong, Marcus Boerger, Sterling Hughes, George Schlossnagle, Ilia Alshanetsky',
        'PHP hash' => 'Sara Golemon, Rasmus Lerdorf, Stefan Esser, Michael Wallner, Scott MacVicar',
        'Posix' => 'Kristian Koehntopp',
        'PostgreSQL driver for PDO' => 'Edin Kadribasic, Ilia Alshanetsky',
        'PostgreSQL' => 'Jouni Ahto, Zeev Suraski, Yasuo Ohgaki, Chris Kings-Lynne',
        'Pspell' => 'Vlad Krupin',
        'random' => 'Go Kudo, Tim Düsterhus, Guilliam Xavier, Christoph M. Becker, Jakub Zelenka, Bob Weinand, Máté Kocsis, and Original RNG implementators',
        'Readline' => 'Thies C. Arntzen',
        'Reflection' => 'Marcus Boerger, Timm Friebe, George Schlossnagle, Andrei Zmievski, Johannes Schlueter',
        'Sessions' => 'Sascha Schumann, Andrei Zmievski',
        'Shared Memory Operations' => 'Slava Poliakov, Ilia Alshanetsky',
        'SimpleXML' => 'Sterling Hughes, Marcus Boerger, Rob Richards',
        'SNMP' => 'Rasmus Lerdorf, Harrie Hazewinkel, Mike Jackson, Steven Lawrance, Johann Hanne, Boris Lytochkin',
        'SOAP' => 'Brad Lafountain, Shane Caraveo, Dmitry Stogov',
        'Sockets' => 'Chris Vandomelen, Sterling Hughes, Daniel Beulshausen, Jason Greene',
        'Sodium' => 'Frank Denis',
        'SPL' => 'Marcus Boerger, Etienne Kneuss',
        'SQLite 3.x driver for PDO' => 'Wez Furlong',
        'SQLite3' => 'Scott MacVicar, Ilia Alshanetsky, Brad Dewar',
        'System V Message based IPC' => 'Wez Furlong',
        'System V Semaphores' => 'Tom May',
        'System V Shared Memory' => 'Christian Cartus',
        'tidy' => 'John Coggeshall, Ilia Alshanetsky',
        'tokenizer' => 'Andrei Zmievski, Johannes Schlueter',
        'XML' => 'Stig Bakken, Thies C. Arntzen, Sterling Hughes',
        'XMLReader' => 'Rob Richards',
        'XMLWriter' => 'Rob Richards, Pierre-Alain Joye',
        'XSL' => 'Christian Stocker, Rob Richards',
        'Zip' => 'Pierre-Alain Joye, Remi Collet',
        'Zlib' => 'Rasmus Lerdorf, Stefan Roehrich, Zeev Suraski, Jade Nicoletti, Michael Wallner',
        'uri' => 'Máté Kocsis, Tim Düsterhus, Ignace Nyamagana Butera, Arnaud Le Blanc, Dennis Snell, Nora Dossche, Nicolas Grekas',
    ];

    /**
     * php-src module author map (ext/standard/info.c credits_modules) — subset for bundled extensions.
     *
     * @var array<string, string>
     */
    public const MODULE_AUTHORS = [
        'bcmath' => 'Andi Gutmans',
        'calendar' => 'Shane Caraveo, Colin Viebrock, Hartmut Holzgraefe, Wez Furlong',
        'ctype' => 'Hartmut Holzgraefe',
        'date' => 'Derick Rethans',
        'dom' => 'Christian Stocker, Rob Richards, Marcus Boerger',
        'filter' => 'Derick Rethans',
        'gettext' => 'Alex Plotnick',
        'hash' => 'Sara Golemon, Boris Lyulin, Michael Wallner',
        'iconv' => 'Rui Hirokawa, Stig Bakken, Moriyoshi Koizumi',
        'json' => 'Jakub Zelenka, Johannes Schlüter',
        'libxml' => 'Christian Stocker, Rob Richards, Marcus Boerger, Wez Furlong, Shane Caraveo',
        'mbstring' => 'Rui Hirokawa, Moriyoshi Koizumi, Wez Furlong, Ilia Alshanetsky',
        'openssl' => 'Stig Venaas, Wez Furlong, Shane Caraveo, Ilia Alshanetsky, Pierre-Alain Joye',
        'pcre' => 'Andrei Zmievski',
        'posix' => 'Kristian Koehntopp',
        'random' => 'Tim Düsterhus, Bob Weinand, Máté Kocsis, George Peter Banyard',
        'readline' => 'Hartmut Holzgraefe, Ilia Alshanetsky',
        'session' => 'Sascha Schumann, Andrei Zmievski',
        'spl' => 'Marcus Boerger, Etienne Kneuss',
        'standard' => 'The PHP Group',
        'stats' => 'Andi Gutmans, Rasmus Lerdorf',
        'tokenizer' => 'Andrei Zmievski, Johannes Schlüter',
        'xml' => 'Christian Stocker, Rob Richards, Marcus Boerger',
        'zlib' => 'Rasmus Lerdorf, Stefan Esser, Jim Winstead, Andrei Zmievski',
        'sodium' => 'Frank Denis',
    ];

    /** php-src PHP_CREDITS_SAPI — Server API credits. */
    public const SAPI_AUTHORS =
        'Andi Gutmans, Shane Caraveo, Zeev Suraski';

    /**
     * php-src credits_sapi — SAPI handler author rows (ext/standard/info.c, #14294).
     *
     * @var array<string, string>
     */
    public const SAPI_MODULES = [
        'Apache 2.0 Handler' => 'Ian Holsman, Justin Erenkrantz (based on Apache 2.0 Filter code)',
        'CGI / FastCGI' => 'Rasmus Lerdorf, Stig Bakken, Shane Caraveo, Dmitry Stogov',
        'CLI' => 'Edin Kadribasic, Marcus Boerger, Johannes Schlueter, Moriyoshi Koizumi, Xinchen Hui',
        'Embed' => 'Edin Kadribasic',
        'FastCGI Process Manager' => 'Andrei Nigmatulin, dreamcat4, Antony Dovgal, Jerome Loyet',
        'litespeed' => 'George Wang',
        'phpdbg' => 'Felipe Pena, Joe Watkins, Bob Weinand',
    ];

    /** php-src Debian Packaging credits row (ext/standard/credits.c, CREDITS_SAPI tail). */
    public const DEBIAN_PACKAGING_AUTHOR = 'DEB.SURY.ORG, an Ondřej Surý project';

    /**
     * php-src PHP Documentation credits rows (ext/standard/credits.c, CREDITS_DOCS).
     *
     * @var array<string, string>
     */
    public const DOCS_CREDITS = [
        'Authors' => 'Mehdi Achour, Friedhelm Betz, Antony Dovgal, Nuno Lopes, Hannes Magnusson, Philip Olson, Georg Richter, Damien Seguy, Jakub Vrana, Adam Harvey',
        'Editor' => 'Peter Cowburn',
        'User Note Maintainers' => 'Daniel P. Brown, Thiago Henrique Pojda',
        'Other Contributors' => 'Previously active authors, editors and other contributors are listed in the manual.',
    ];

    /**
     * php-src Websites and Infrastructure credits (ext/standard/credits.c, CREDITS_WEB).
     *
     * @var array<string, string>
     */
    public const WEB_CREDITS = [
        'PHP Websites Team' => 'Rasmus Lerdorf, Hannes Magnusson, Philip Olson, Lukas Kahwe Smith, Pierre-Alain Joye, Kalle Sommer Nielsen, Peter Cowburn, Adam Harvey, Ferenc Kovacs, Levi Morrison',
        'Event Maintainers' => 'Damien Seguy, Daniel P. Brown',
        'Network Infrastructure' => 'Daniel P. Brown',
        'Windows Infrastructure' => 'Alex Schoenmaker',
    ];

    /** @deprecated Use {@see DOCS_CREDITS} — kept for legacy callers. */
    public const DOCS_TEAM =
        'Mehdi Achour, Vincent Gevers, Stig Bakken, Rasmus Lerdorf, '
        .'Gabor Egressy, Hartmut Holzgraefe, Jouni Ahto';

    /**
     * php-src credits_modules[] extension name → display label (ext/standard/info.c, #14799).
     *
     * @var array<string, string>
     */
    private const EXTENSION_CREDITS_MODULE = [
        'bcmath' => 'BC Math',
        'bz2' => 'Bzip2',
        'calendar' => 'Calendar',
        'com_dotnet' => 'COM and .Net',
        'ctype' => 'ctype',
        'curl' => 'cURL',
        'date' => 'Date/Time Support',
        'mssql' => 'DB-LIB (MS SQL, Sybase)',
        'pdo_dblib' => 'DB-LIB (MS SQL, Sybase)',
        'dba' => 'DBA',
        'dom' => 'DOM',
        'enchant' => 'enchant',
        'exif' => 'EXIF',
        'ffi' => 'FFI',
        'fileinfo' => 'fileinfo',
        'pdo_firebird' => 'Firebird driver for PDO',
        'ftp' => 'FTP',
        'gd' => 'GD imaging',
        'gettext' => 'GetText',
        'gmp' => 'GNU GMP support',
        'iconv' => 'Iconv',
        'imap' => 'IMAP',
        'filter' => 'Input Filter',
        'intl' => 'Internationalization',
        'json' => 'JSON',
        'ldap' => 'LDAP',
        'libxml' => 'LIBXML',
        'mbstring' => 'Multibyte String Functions',
        'pdo_mysql' => 'MySQL driver for PDO',
        'mysqli' => 'MySQLi',
        'mysqlnd' => 'MySQLnd',
        'oci8' => 'OCI8',
        'pdo_odbc' => 'ODBC driver for PDO',
        'odbc' => 'ODBC',
        'zend opcache' => 'Opcache',
        'opcache' => 'Opcache',
        'openssl' => 'OpenSSL',
        'pdo_oci' => 'Oracle (OCI) driver for PDO',
        'pcntl' => 'pcntl',
        'pcre' => 'Perl Compatible Regexps',
        'phar' => 'PHP Archive',
        'pdo' => 'PHP Data Objects',
        'hash' => 'PHP hash',
        'posix' => 'Posix',
        'pdo_pgsql' => 'PostgreSQL driver for PDO',
        'pgsql' => 'PostgreSQL',
        'pspell' => 'Pspell',
        'random' => 'random',
        'readline' => 'Readline',
        'reflection' => 'Reflection',
        'session' => 'Sessions',
        'shmop' => 'Shared Memory Operations',
        'simplexml' => 'SimpleXML',
        'snmp' => 'SNMP',
        'soap' => 'SOAP',
        'sockets' => 'Sockets',
        'sodium' => 'Sodium',
        'spl' => 'SPL',
        'pdo_sqlite' => 'SQLite 3.x driver for PDO',
        'sqlite3' => 'SQLite3',
        'sysvmsg' => 'System V Message based IPC',
        'sysvsem' => 'System V Semaphores',
        'sysvshm' => 'System V Shared Memory',
        'tidy' => 'tidy',
        'tokenizer' => 'tokenizer',
        'xml' => 'XML',
        'xmlreader' => 'XMLReader',
        'xmlwriter' => 'XMLWriter',
        'xsl' => 'XSL',
        'zip' => 'Zip',
        'zlib' => 'Zlib',
        'uri' => 'uri',
    ];

    /**
     * Full php-src credits_modules table (unfiltered; tests only).
     *
     * @return array<string, string>
     */
    public static function allModuleAuthors(): array
    {
        return self::creditsModuleAuthors();
    }

    /**
     * Profile-aware credits_modules[] (ext/standard/credits.c, #16740).
     *
     * @return array<string, string>
     */
    public static function creditsModuleAuthors(): array
    {
        if (CompilerVersion::supportsForwardProfileCreditsModuleAuthors()) {
            return self::CREDITS_MODULE_AUTHORS;
        }

        $rows = self::CREDITS_MODULE_AUTHORS;
        $rows['DOM'] = 'Christian Stocker, Rob Richards, Marcus Boerger';
        unset($rows['uri']);

        return $rows;
    }

    /**
     * php-src credits_modules[] for phpinfo(INFO_CREDITS) — full static table (ext/standard/info.c).
     *
     * @return array<string, string> module label => authors
     */
    public static function creditsModuleAuthorsForPhpinfo(): array
    {
        $rows = self::creditsModuleAuthors();
        ksort($rows, SORT_STRING);

        return $rows;
    }

    /**
     * credits_modules rows for loaded extensions only (php-src info.c, #14799).
     *
     * @return array<string, string> module label => authors
     */
    public static function creditsModuleAuthorsForLoadedExtensions(): array
    {
        $rows = [];
        $seenLabels = [];
        foreach (ModuleRegistry::getLoadedExtensions() as $name) {
            $key = strtolower($name);
            if ('core' === $key || 'types' === $key || 'standard' === $key) {
                continue;
            }
            $label = self::EXTENSION_CREDITS_MODULE[$key] ?? null;
            if (null === $label || isset($seenLabels[$label])) {
                continue;
            }
            $authors = self::creditsModuleAuthors()[$label] ?? null;
            if (null === $authors) {
                continue;
            }
            $seenLabels[$label] = true;
            $rows[$label] = $authors;
        }
        ksort($rows, SORT_STRING);

        return $rows;
    }

    /**
     * @return array<string, string> extension => authors for loaded modules (phpinfo modules section)
     */
    public static function moduleAuthorsForLoadedExtensions(): array
    {
        $rows = [];
        foreach (ModuleRegistry::getLoadedExtensions() as $name) {
            $key = strtolower($name);
            if ('core' === $key || 'types' === $key) {
                continue;
            }
            $label = self::moduleDisplayName($key);
            $rows[$label] = self::MODULE_AUTHORS[$key] ?? 'The PHP Group';
        }
        ksort($rows, SORT_STRING);

        return $rows;
    }

    private static function moduleDisplayName(string $extension): string
    {
        return match ($extension) {
            'date' => 'Date/Time Support',
            'mbstring' => 'Multibyte String Functions',
            'openssl' => 'OpenSSL',
            'pcre' => 'PCRE',
            'spl' => 'SPL',
            'standard' => 'Standard PHP Library',
            'xml' => 'XML',
            'zlib' => 'Zlib',
            'json' => 'JSON',
            'hash' => 'HASH Message Digest Framework',
            'session' => 'Session Handling',
            'filter' => 'Filter',
            'dom' => 'DOM',
            'libxml' => 'libxml',
            'iconv' => 'Iconv',
            'gettext' => 'GetText',
            'calendar' => 'Calendar',
            'ctype' => 'ctype',
            'posix' => 'POSIX',
            'tokenizer' => 'Tokenizer',
            'random' => 'Random',
            'readline' => 'Readline',
            'stats' => 'Statistics',
            'sodium' => 'Sodium',
            default => ucfirst($extension),
        };
    }
}
