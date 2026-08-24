<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mbregex option-string parse/serialize — php-src ext/mbstring/php_mbregex.c
 * `_php_mb_regex_init_options` / `_php_mb_regex_get_option_string` (#34438 / #4635).
 *
 * Option flags are bit-ored; syntax mode is the last mode char (default Ruby `r`).
 * Serialization order is fixed: i x p|m|s l n then syntax.
 */
final class MbRegexOptions
{
    private const OPT_IGNORECASE = 1;

    private const OPT_EXTEND = 2;

    private const OPT_MULTILINE = 4;

    private const OPT_SINGLELINE = 8;

    private const OPT_FIND_LONGEST = 16;

    private const OPT_FIND_NOT_EMPTY = 32;

    private const SYNTAX_RUBY = 'r';

    private const SYNTAX_JAVA = 'j';

    private const SYNTAX_GNU = 'u';

    private const SYNTAX_GREP = 'g';

    private const SYNTAX_EMACS = 'c';

    private const SYNTAX_PERL = 'z';

    private const SYNTAX_POSIX_BASIC = 'b';

    private const SYNTAX_POSIX_EXTENDED = 'd';

    /**
     * Parse + normalize like Zend. Empty string → `r` (Ruby syntax, no flags).
     *
     * @throws \ValueError unsupported option character
     */
    public static function normalize(string $options): string
    {
        $flags = 0;
        $syntax = self::SYNTAX_RUBY;
        $n = \strlen($options);
        for ($i = 0; $i < $n; ++$i) {
            $c = $options[$i];
            switch ($c) {
                case 'i':
                    $flags |= self::OPT_IGNORECASE;
                    break;
                case 'x':
                    $flags |= self::OPT_EXTEND;
                    break;
                case 'm':
                    $flags |= self::OPT_MULTILINE;
                    break;
                case 's':
                    $flags |= self::OPT_SINGLELINE;
                    break;
                case 'p':
                    $flags |= self::OPT_MULTILINE | self::OPT_SINGLELINE;
                    break;
                case 'l':
                    $flags |= self::OPT_FIND_LONGEST;
                    break;
                case 'n':
                    $flags |= self::OPT_FIND_NOT_EMPTY;
                    break;
                case 'j':
                    $syntax = self::SYNTAX_JAVA;
                    break;
                case 'u':
                    $syntax = self::SYNTAX_GNU;
                    break;
                case 'g':
                    $syntax = self::SYNTAX_GREP;
                    break;
                case 'c':
                    $syntax = self::SYNTAX_EMACS;
                    break;
                case 'r':
                    $syntax = self::SYNTAX_RUBY;
                    break;
                case 'z':
                    $syntax = self::SYNTAX_PERL;
                    break;
                case 'b':
                    $syntax = self::SYNTAX_POSIX_BASIC;
                    break;
                case 'd':
                    $syntax = self::SYNTAX_POSIX_EXTENDED;
                    break;
                default:
                    throw new \ValueError(sprintf('Option "%s" is not supported', $c));
            }
        }

        return self::serialize($flags, $syntax);
    }

    private static function serialize(int $flags, string $syntax): string
    {
        $out = '';
        if (0 !== ($flags & self::OPT_IGNORECASE)) {
            $out .= 'i';
        }
        if (0 !== ($flags & self::OPT_EXTEND)) {
            $out .= 'x';
        }
        $ms = self::OPT_MULTILINE | self::OPT_SINGLELINE;
        if ($ms === ($flags & $ms)) {
            $out .= 'p';
        } else {
            if (0 !== ($flags & self::OPT_MULTILINE)) {
                $out .= 'm';
            }
            if (0 !== ($flags & self::OPT_SINGLELINE)) {
                $out .= 's';
            }
        }
        if (0 !== ($flags & self::OPT_FIND_LONGEST)) {
            $out .= 'l';
        }
        if (0 !== ($flags & self::OPT_FIND_NOT_EMPTY)) {
            $out .= 'n';
        }

        return $out.$syntax;
    }
}
