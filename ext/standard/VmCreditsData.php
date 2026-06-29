<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

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

    /** php-src PHP_CREDITS_DOCS — documentation team. */
    public const DOCS_TEAM =
        'Mehdi Achour, Vincent Gevers, Stig Bakken, Rasmus Lerdorf, '
        .'Gabor Egressy, Hartmut Holzgraefe, Jouni Ahto';

    /**
     * @return array<string, string> extension => authors for loaded modules
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
