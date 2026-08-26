<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT runtime helper for html_entity_decode() (#4130, #35069).
 *
 * Self-contained NestedJIT TU (peer HtmlspecialcharsDecodeJitHelper #27050 /
 * HtmlEntitiesJitHelper #26889): no VmString call, recurse with char.$rest —
 * loop-carried string accumulators miscompile under NestedJIT.
 *
 * php-src: ext/standard/html.c — php_unescape_html_entities()
 */
final class HtmlEntityDecodeJitHelper
{
    public static function decode(string $string, int $flags): string
    {
        return self::decodeFrom($string, $flags, 0);
    }

    public static function decodeWithEncoding(string $string, int $flags, string $encoding): string
    {
        // NestedJIT path is UTF-8 only; encoding arg kept for ABI parity with VM.
        return self::decodeFrom($string, $flags, 0);
    }

    /** Public so NestedJIT helper TUs bind the recursive callee (#27050 / #35069). */
    public static function decodeFrom(string $string, int $flags, int $i): string
    {
        $len = \strlen($string);
        if ($i >= $len) {
            return '';
        }
        if ('&' !== $string[$i]) {
            return $string[$i].self::decodeFrom($string, $flags, $i + 1);
        }

        $decodeDouble = 0 !== ($flags & 2);
        $decodeSingle = 0 !== ($flags & 1);

        if (self::entityMatch($string, $i, '&amp;', 0)) {
            return '&'.self::decodeFrom($string, $flags, $i + 5);
        }
        if (self::entityMatch($string, $i, '&lt;', 0)) {
            return '<'.self::decodeFrom($string, $flags, $i + 4);
        }
        if (self::entityMatch($string, $i, '&gt;', 0)) {
            return '>'.self::decodeFrom($string, $flags, $i + 4);
        }
        if ($decodeDouble && self::entityMatch($string, $i, '&quot;', 0)) {
            return '"'.self::decodeFrom($string, $flags, $i + 6);
        }
        if ($decodeSingle && self::entityMatch($string, $i, '&#039;', 0)) {
            return "'".self::decodeFrom($string, $flags, $i + 6);
        }
        if ($decodeSingle && self::entityMatch($string, $i, '&#39;', 0)) {
            return "'".self::decodeFrom($string, $flags, $i + 5);
        }
        if (0 !== ($flags & \ENT_HTML5) && $decodeSingle
            && self::entityMatch($string, $i, '&apos;', 0)) {
            return "'".self::decodeFrom($string, $flags, $i + 6);
        }

        $semi = self::findSemi($string, $i + 1, $len, 0);
        if ($semi > $i + 1 && $semi - $i <= 33) {
            $entity = self::copyBytes($string, $i, $semi - $i + 1);
            $map = self::namedEntityDecodeMap();
            if (isset($map[$entity])) {
                $decoded = $map[$entity];
                if ("'" === $decoded && !$decodeSingle) {
                    return $entity.self::decodeFrom($string, $flags, $semi + 1);
                }
                if ('"' === $decoded && !$decodeDouble) {
                    return $entity.self::decodeFrom($string, $flags, $semi + 1);
                }

                return $decoded.self::decodeFrom($string, $flags, $semi + 1);
            }
            $numeric = self::decodeNumericEntity($entity);
            if (null !== $numeric) {
                return $numeric.self::decodeFrom($string, $flags, $semi + 1);
            }
        }

        return '&'.self::decodeFrom($string, $flags, $i + 1);
    }

    /** Recursive entity match — NestedJIT-safe (peer HtmlspecialcharsDecodeJitHelper). */
    public static function entityMatch(string $string, int $i, string $entity, int $j): bool
    {
        if (!isset($entity[$j])) {
            return true;
        }
        if (!isset($string[$i + $j]) || $string[$i + $j] !== $entity[$j]) {
            return false;
        }

        return self::entityMatch($string, $i, $entity, $j + 1);
    }

    /** Find '; within 32 bytes without NestedJIT-hostile strpos on variable haystack. */
    public static function findSemi(string $string, int $i, int $len, int $n): int
    {
        if ($n > 32 || $i >= $len) {
            return -1;
        }
        if (';' === $string[$i]) {
            return $i;
        }

        return self::findSemi($string, $i + 1, $len, $n + 1);
    }

    public static function copyBytes(string $string, int $start, int $n): string
    {
        if ($n <= 0) {
            return '';
        }

        return $string[$start].self::copyBytes($string, $start + 1, $n - 1);
    }

    public static function decodeNumericEntity(string $entity): ?string
    {
        $len = \strlen($entity);
        if ($len < 4 || '&' !== $entity[0] || '#' !== $entity[1] || ';' !== $entity[$len - 1]) {
            return null;
        }
        $hex = ('x' === $entity[2] || 'X' === $entity[2]);
        $bodyStart = $hex ? 3 : 2;
        if ($bodyStart >= $len - 1) {
            return null;
        }
        $code = 0;
        for ($j = $bodyStart; $j < $len - 1; ++$j) {
            $c = $entity[$j];
            if ($hex) {
                if ($c >= '0' && $c <= '9') {
                    $code = ($code << 4) + (\ord($c) - 48);
                } elseif ($c >= 'a' && $c <= 'f') {
                    $code = ($code << 4) + (\ord($c) - 87);
                } elseif ($c >= 'A' && $c <= 'F') {
                    $code = ($code << 4) + (\ord($c) - 55);
                } else {
                    return null;
                }
            } else {
                if ($c < '0' || $c > '9') {
                    return null;
                }
                $code = $code * 10 + (\ord($c) - 48);
            }
        }
        if ($code < 0 || $code > 0x10FFFF) {
            return null;
        }
        if ($code <= 0x7F) {
            return \chr($code);
        }
        if ($code <= 0x7FF) {
            return \chr(0xC0 | ($code >> 6)).\chr(0x80 | ($code & 0x3F));
        }
        if ($code <= 0xFFFF) {
            return \chr(0xE0 | ($code >> 12))
                .\chr(0x80 | (($code >> 6) & 0x3F))
                .\chr(0x80 | ($code & 0x3F));
        }

        return \chr(0xF0 | ($code >> 18))
            .\chr(0x80 | (($code >> 12) & 0x3F))
            .\chr(0x80 | (($code >> 6) & 0x3F))
            .\chr(0x80 | ($code & 0x3F));
    }

    /** @return array<string, string> */
    public static function namedEntityDecodeMap(): array
    {
        return [
            '&quot;' => '"',
            '&amp;' => '&',
            '&#039;' => '\'',
            '&lt;' => '<',
            '&gt;' => '>',
            '&nbsp;' => ' ',
            '&iexcl;' => '¡',
            '&cent;' => '¢',
            '&pound;' => '£',
            '&curren;' => '¤',
            '&yen;' => '¥',
            '&brvbar;' => '¦',
            '&sect;' => '§',
            '&uml;' => '¨',
            '&copy;' => '©',
            '&ordf;' => 'ª',
            '&laquo;' => '«',
            '&not;' => '¬',
            '&shy;' => '­',
            '&reg;' => '®',
            '&macr;' => '¯',
            '&deg;' => '°',
            '&plusmn;' => '±',
            '&sup2;' => '²',
            '&sup3;' => '³',
            '&acute;' => '´',
            '&micro;' => 'µ',
            '&para;' => '¶',
            '&middot;' => '·',
            '&cedil;' => '¸',
            '&sup1;' => '¹',
            '&ordm;' => 'º',
            '&raquo;' => '»',
            '&frac14;' => '¼',
            '&frac12;' => '½',
            '&frac34;' => '¾',
            '&iquest;' => '¿',
            '&Agrave;' => 'À',
            '&Aacute;' => 'Á',
            '&Acirc;' => 'Â',
            '&Atilde;' => 'Ã',
            '&Auml;' => 'Ä',
            '&Aring;' => 'Å',
            '&AElig;' => 'Æ',
            '&Ccedil;' => 'Ç',
            '&Egrave;' => 'È',
            '&Eacute;' => 'É',
            '&Ecirc;' => 'Ê',
            '&Euml;' => 'Ë',
            '&Igrave;' => 'Ì',
            '&Iacute;' => 'Í',
            '&Icirc;' => 'Î',
            '&Iuml;' => 'Ï',
            '&ETH;' => 'Ð',
            '&Ntilde;' => 'Ñ',
            '&Ograve;' => 'Ò',
            '&Oacute;' => 'Ó',
            '&Ocirc;' => 'Ô',
            '&Otilde;' => 'Õ',
            '&Ouml;' => 'Ö',
            '&times;' => '×',
            '&Oslash;' => 'Ø',
            '&Ugrave;' => 'Ù',
            '&Uacute;' => 'Ú',
            '&Ucirc;' => 'Û',
            '&Uuml;' => 'Ü',
            '&Yacute;' => 'Ý',
            '&THORN;' => 'Þ',
            '&szlig;' => 'ß',
            '&agrave;' => 'à',
            '&aacute;' => 'á',
            '&acirc;' => 'â',
            '&atilde;' => 'ã',
            '&auml;' => 'ä',
            '&aring;' => 'å',
            '&aelig;' => 'æ',
            '&ccedil;' => 'ç',
            '&egrave;' => 'è',
            '&eacute;' => 'é',
            '&ecirc;' => 'ê',
            '&euml;' => 'ë',
            '&igrave;' => 'ì',
            '&iacute;' => 'í',
            '&icirc;' => 'î',
            '&iuml;' => 'ï',
            '&eth;' => 'ð',
            '&ntilde;' => 'ñ',
            '&ograve;' => 'ò',
            '&oacute;' => 'ó',
            '&ocirc;' => 'ô',
            '&otilde;' => 'õ',
            '&ouml;' => 'ö',
            '&divide;' => '÷',
            '&oslash;' => 'ø',
            '&ugrave;' => 'ù',
            '&uacute;' => 'ú',
            '&ucirc;' => 'û',
            '&uuml;' => 'ü',
            '&yacute;' => 'ý',
            '&thorn;' => 'þ',
            '&yuml;' => 'ÿ',
            '&OElig;' => 'Œ',
            '&oelig;' => 'œ',
            '&Scaron;' => 'Š',
            '&scaron;' => 'š',
            '&Yuml;' => 'Ÿ',
            '&fnof;' => 'ƒ',
            '&circ;' => 'ˆ',
            '&tilde;' => '˜',
            '&Alpha;' => 'Α',
            '&Beta;' => 'Β',
            '&Gamma;' => 'Γ',
            '&Delta;' => 'Δ',
            '&Epsilon;' => 'Ε',
            '&Zeta;' => 'Ζ',
            '&Eta;' => 'Η',
            '&Theta;' => 'Θ',
            '&Iota;' => 'Ι',
            '&Kappa;' => 'Κ',
            '&Lambda;' => 'Λ',
            '&Mu;' => 'Μ',
            '&Nu;' => 'Ν',
            '&Xi;' => 'Ξ',
            '&Omicron;' => 'Ο',
            '&Pi;' => 'Π',
            '&Rho;' => 'Ρ',
            '&Sigma;' => 'Σ',
            '&Tau;' => 'Τ',
            '&Upsilon;' => 'Υ',
            '&Phi;' => 'Φ',
            '&Chi;' => 'Χ',
            '&Psi;' => 'Ψ',
            '&Omega;' => 'Ω',
            '&alpha;' => 'α',
            '&beta;' => 'β',
            '&gamma;' => 'γ',
            '&delta;' => 'δ',
            '&epsilon;' => 'ε',
            '&zeta;' => 'ζ',
            '&eta;' => 'η',
            '&theta;' => 'θ',
            '&iota;' => 'ι',
            '&kappa;' => 'κ',
            '&lambda;' => 'λ',
            '&mu;' => 'μ',
            '&nu;' => 'ν',
            '&xi;' => 'ξ',
            '&omicron;' => 'ο',
            '&pi;' => 'π',
            '&rho;' => 'ρ',
            '&sigmaf;' => 'ς',
            '&sigma;' => 'σ',
            '&tau;' => 'τ',
            '&upsilon;' => 'υ',
            '&phi;' => 'φ',
            '&chi;' => 'χ',
            '&psi;' => 'ψ',
            '&omega;' => 'ω',
            '&thetasym;' => 'ϑ',
            '&upsih;' => 'ϒ',
            '&piv;' => 'ϖ',
            '&ensp;' => ' ',
            '&emsp;' => ' ',
            '&thinsp;' => ' ',
            '&zwnj;' => '‌',
            '&zwj;' => '‍',
            '&lrm;' => '‎',
            '&rlm;' => '‏',
            '&ndash;' => '–',
            '&mdash;' => '—',
            '&lsquo;' => '‘',
            '&rsquo;' => '’',
            '&sbquo;' => '‚',
            '&ldquo;' => '“',
            '&rdquo;' => '”',
            '&bdquo;' => '„',
            '&dagger;' => '†',
            '&Dagger;' => '‡',
            '&bull;' => '•',
            '&hellip;' => '…',
            '&permil;' => '‰',
            '&prime;' => '′',
            '&Prime;' => '″',
            '&lsaquo;' => '‹',
            '&rsaquo;' => '›',
            '&oline;' => '‾',
            '&frasl;' => '⁄',
            '&euro;' => '€',
            '&image;' => 'ℑ',
            '&weierp;' => '℘',
            '&real;' => 'ℜ',
            '&trade;' => '™',
            '&alefsym;' => 'ℵ',
            '&larr;' => '←',
            '&uarr;' => '↑',
            '&rarr;' => '→',
            '&darr;' => '↓',
            '&harr;' => '↔',
            '&crarr;' => '↵',
            '&lArr;' => '⇐',
            '&uArr;' => '⇑',
            '&rArr;' => '⇒',
            '&dArr;' => '⇓',
            '&hArr;' => '⇔',
            '&forall;' => '∀',
            '&part;' => '∂',
            '&exist;' => '∃',
            '&empty;' => '∅',
            '&nabla;' => '∇',
            '&isin;' => '∈',
            '&notin;' => '∉',
            '&ni;' => '∋',
            '&prod;' => '∏',
            '&sum;' => '∑',
            '&minus;' => '−',
            '&lowast;' => '∗',
            '&radic;' => '√',
            '&prop;' => '∝',
            '&infin;' => '∞',
            '&ang;' => '∠',
            '&and;' => '∧',
            '&or;' => '∨',
            '&cap;' => '∩',
            '&cup;' => '∪',
            '&int;' => '∫',
            '&there4;' => '∴',
            '&sim;' => '∼',
            '&cong;' => '≅',
            '&asymp;' => '≈',
            '&ne;' => '≠',
            '&equiv;' => '≡',
            '&le;' => '≤',
            '&ge;' => '≥',
            '&sub;' => '⊂',
            '&sup;' => '⊃',
            '&nsub;' => '⊄',
            '&sube;' => '⊆',
            '&supe;' => '⊇',
            '&oplus;' => '⊕',
            '&otimes;' => '⊗',
            '&perp;' => '⊥',
            '&sdot;' => '⋅',
            '&lceil;' => '⌈',
            '&rceil;' => '⌉',
            '&lfloor;' => '⌊',
            '&rfloor;' => '⌋',
            '&lang;' => '〈',
            '&rang;' => '〉',
            '&loz;' => '◊',
            '&spades;' => '♠',
            '&clubs;' => '♣',
            '&hearts;' => '♥',
            '&diams;' => '♦',
            '&#39;' => '\'',
        ];
    }
}
