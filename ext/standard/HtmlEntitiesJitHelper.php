<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT runtime helper for htmlentities() UTF-8 entity translation (#10734, #26889).
 *
 * Self-contained for NestedJIT thin AOT (#16075 / peer HtmlspecialcharsJitHelper #25345):
 * no VmString call, no loop-carried string accumulator — recurse with char.$rest.
 * php-src: ext/standard/html.c — php_html_entities()
 */
final class HtmlEntitiesJitHelper
{
    public static function encode(string $string, int $flags): string
    {
        return self::escapeFrom($string, $flags, 0, true);
    }

    /** Public so NestedJIT helper TUs bind the recursive callee (#25345 / #26889). */
    public static function escapeFrom(string $string, int $flags, int $i, bool $doubleEncode): string
    {
        if (!isset($string[$i])) {
            return '';
        }
        $width = self::utf8CharWidth($string, $i);
        if ($width < 1) {
            return '';
        }
        $char = self::copyBytes($string, $i, $width);
        if ('&' === $char && !$doubleEncode) {
            $entityLen = self::existingEntityLen($string, $i);
            if ($entityLen > 0) {
                return self::copyBytes($string, $i, $entityLen)
                    .self::escapeFrom($string, $flags, $i + $entityLen, $doubleEncode);
            }
        }
        $rest = self::escapeFrom($string, $flags, $i + $width, $doubleEncode);
        $mapped = self::lookupEntity($char, $flags);

        return $mapped.$rest;
    }

    public static function lookupEntity(string $char, int $flags): string
    {
        $quoteBoth = \ENT_QUOTES === ($flags & \ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & \ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & \ENT_HTML5);
        if ('"' === $char) {
            return ($quoteBoth || $quoteDouble) ? '&quot;' : '"';
        }
        if ("'" === $char) {
            if ($quoteBoth) {
                return $entHtml5 ? '&apos;' : '&#039;';
            }

            return "'";
        }
        $map = self::entitiesEntQuotesCore();
        if (isset($map[$char])) {
            return $map[$char];
        }

        return $char;
    }

    /**
     * HTML_ENTITIES core map (php-src html.c ENT_QUOTES) without quote keys —
     * quotes handled in {@see lookupEntity} from flags.
     *
     * @return array<string, string>
     */
    public static function entitiesEntQuotesCore(): array
    {
        return [
            '&' => '&amp;',
            '<' => '&lt;',
            '>' => '&gt;',
            ' ' => '&nbsp;',
            '¡' => '&iexcl;',
            '¢' => '&cent;',
            '£' => '&pound;',
            '¤' => '&curren;',
            '¥' => '&yen;',
            '¦' => '&brvbar;',
            '§' => '&sect;',
            '¨' => '&uml;',
            '©' => '&copy;',
            'ª' => '&ordf;',
            '«' => '&laquo;',
            '¬' => '&not;',
            '­' => '&shy;',
            '®' => '&reg;',
            '¯' => '&macr;',
            '°' => '&deg;',
            '±' => '&plusmn;',
            '²' => '&sup2;',
            '³' => '&sup3;',
            '´' => '&acute;',
            'µ' => '&micro;',
            '¶' => '&para;',
            '·' => '&middot;',
            '¸' => '&cedil;',
            '¹' => '&sup1;',
            'º' => '&ordm;',
            '»' => '&raquo;',
            '¼' => '&frac14;',
            '½' => '&frac12;',
            '¾' => '&frac34;',
            '¿' => '&iquest;',
            'À' => '&Agrave;',
            'Á' => '&Aacute;',
            'Â' => '&Acirc;',
            'Ã' => '&Atilde;',
            'Ä' => '&Auml;',
            'Å' => '&Aring;',
            'Æ' => '&AElig;',
            'Ç' => '&Ccedil;',
            'È' => '&Egrave;',
            'É' => '&Eacute;',
            'Ê' => '&Ecirc;',
            'Ë' => '&Euml;',
            'Ì' => '&Igrave;',
            'Í' => '&Iacute;',
            'Î' => '&Icirc;',
            'Ï' => '&Iuml;',
            'Ð' => '&ETH;',
            'Ñ' => '&Ntilde;',
            'Ò' => '&Ograve;',
            'Ó' => '&Oacute;',
            'Ô' => '&Ocirc;',
            'Õ' => '&Otilde;',
            'Ö' => '&Ouml;',
            '×' => '&times;',
            'Ø' => '&Oslash;',
            'Ù' => '&Ugrave;',
            'Ú' => '&Uacute;',
            'Û' => '&Ucirc;',
            'Ü' => '&Uuml;',
            'Ý' => '&Yacute;',
            'Þ' => '&THORN;',
            'ß' => '&szlig;',
            'à' => '&agrave;',
            'á' => '&aacute;',
            'â' => '&acirc;',
            'ã' => '&atilde;',
            'ä' => '&auml;',
            'å' => '&aring;',
            'æ' => '&aelig;',
            'ç' => '&ccedil;',
            'è' => '&egrave;',
            'é' => '&eacute;',
            'ê' => '&ecirc;',
            'ë' => '&euml;',
            'ì' => '&igrave;',
            'í' => '&iacute;',
            'î' => '&icirc;',
            'ï' => '&iuml;',
            'ð' => '&eth;',
            'ñ' => '&ntilde;',
            'ò' => '&ograve;',
            'ó' => '&oacute;',
            'ô' => '&ocirc;',
            'õ' => '&otilde;',
            'ö' => '&ouml;',
            '÷' => '&divide;',
            'ø' => '&oslash;',
            'ù' => '&ugrave;',
            'ú' => '&uacute;',
            'û' => '&ucirc;',
            'ü' => '&uuml;',
            'ý' => '&yacute;',
            'þ' => '&thorn;',
            'ÿ' => '&yuml;',
            'Œ' => '&OElig;',
            'œ' => '&oelig;',
            'Š' => '&Scaron;',
            'š' => '&scaron;',
            'Ÿ' => '&Yuml;',
            'ƒ' => '&fnof;',
            'ˆ' => '&circ;',
            '˜' => '&tilde;',
            'Α' => '&Alpha;',
            'Β' => '&Beta;',
            'Γ' => '&Gamma;',
            'Δ' => '&Delta;',
            'Ε' => '&Epsilon;',
            'Ζ' => '&Zeta;',
            'Η' => '&Eta;',
            'Θ' => '&Theta;',
            'Ι' => '&Iota;',
            'Κ' => '&Kappa;',
            'Λ' => '&Lambda;',
            'Μ' => '&Mu;',
            'Ν' => '&Nu;',
            'Ξ' => '&Xi;',
            'Ο' => '&Omicron;',
            'Π' => '&Pi;',
            'Ρ' => '&Rho;',
            'Σ' => '&Sigma;',
            'Τ' => '&Tau;',
            'Υ' => '&Upsilon;',
            'Φ' => '&Phi;',
            'Χ' => '&Chi;',
            'Ψ' => '&Psi;',
            'Ω' => '&Omega;',
            'α' => '&alpha;',
            'β' => '&beta;',
            'γ' => '&gamma;',
            'δ' => '&delta;',
            'ε' => '&epsilon;',
            'ζ' => '&zeta;',
            'η' => '&eta;',
            'θ' => '&theta;',
            'ι' => '&iota;',
            'κ' => '&kappa;',
            'λ' => '&lambda;',
            'μ' => '&mu;',
            'ν' => '&nu;',
            'ξ' => '&xi;',
            'ο' => '&omicron;',
            'π' => '&pi;',
            'ρ' => '&rho;',
            'ς' => '&sigmaf;',
            'σ' => '&sigma;',
            'τ' => '&tau;',
            'υ' => '&upsilon;',
            'φ' => '&phi;',
            'χ' => '&chi;',
            'ψ' => '&psi;',
            'ω' => '&omega;',
            'ϑ' => '&thetasym;',
            'ϒ' => '&upsih;',
            'ϖ' => '&piv;',
            ' ' => '&ensp;',
            ' ' => '&emsp;',
            ' ' => '&thinsp;',
            '‌' => '&zwnj;',
            '‍' => '&zwj;',
            '‎' => '&lrm;',
            '‏' => '&rlm;',
            '–' => '&ndash;',
            '—' => '&mdash;',
            '‘' => '&lsquo;',
            '’' => '&rsquo;',
            '‚' => '&sbquo;',
            '“' => '&ldquo;',
            '”' => '&rdquo;',
            '„' => '&bdquo;',
            '†' => '&dagger;',
            '‡' => '&Dagger;',
            '•' => '&bull;',
            '…' => '&hellip;',
            '‰' => '&permil;',
            '′' => '&prime;',
            '″' => '&Prime;',
            '‹' => '&lsaquo;',
            '›' => '&rsaquo;',
            '‾' => '&oline;',
            '⁄' => '&frasl;',
            '€' => '&euro;',
            'ℑ' => '&image;',
            '℘' => '&weierp;',
            'ℜ' => '&real;',
            '™' => '&trade;',
            'ℵ' => '&alefsym;',
            '←' => '&larr;',
            '↑' => '&uarr;',
            '→' => '&rarr;',
            '↓' => '&darr;',
            '↔' => '&harr;',
            '↵' => '&crarr;',
            '⇐' => '&lArr;',
            '⇑' => '&uArr;',
            '⇒' => '&rArr;',
            '⇓' => '&dArr;',
            '⇔' => '&hArr;',
            '∀' => '&forall;',
            '∂' => '&part;',
            '∃' => '&exist;',
            '∅' => '&empty;',
            '∇' => '&nabla;',
            '∈' => '&isin;',
            '∉' => '&notin;',
            '∋' => '&ni;',
            '∏' => '&prod;',
            '∑' => '&sum;',
            '−' => '&minus;',
            '∗' => '&lowast;',
            '√' => '&radic;',
            '∝' => '&prop;',
            '∞' => '&infin;',
            '∠' => '&ang;',
            '∧' => '&and;',
            '∨' => '&or;',
            '∩' => '&cap;',
            '∪' => '&cup;',
            '∫' => '&int;',
            '∴' => '&there4;',
            '∼' => '&sim;',
            '≅' => '&cong;',
            '≈' => '&asymp;',
            '≠' => '&ne;',
            '≡' => '&equiv;',
            '≤' => '&le;',
            '≥' => '&ge;',
            '⊂' => '&sub;',
            '⊃' => '&sup;',
            '⊄' => '&nsub;',
            '⊆' => '&sube;',
            '⊇' => '&supe;',
            '⊕' => '&oplus;',
            '⊗' => '&otimes;',
            '⊥' => '&perp;',
            '⋅' => '&sdot;',
            '⌈' => '&lceil;',
            '⌉' => '&rceil;',
            '⌊' => '&lfloor;',
            '⌋' => '&rfloor;',
            '〈' => '&lang;',
            '〉' => '&rang;',
            '◊' => '&loz;',
            '♠' => '&spades;',
            '♣' => '&clubs;',
            '♥' => '&hearts;',
            '♦' => '&diams;',
        ];
    }

    public static function utf8CharWidth(string $string, int $i): int
    {
        if (!isset($string[$i])) {
            return 0;
        }
        $b = \ord($string[$i]);
        if ($b < 0x80) {
            return 1;
        }
        if ($b < 0xE0) {
            return isset($string[$i + 1]) ? 2 : 1;
        }
        if ($b < 0xF0) {
            return (isset($string[$i + 1]) && isset($string[$i + 2])) ? 3 : 1;
        }

        return (isset($string[$i + 1]) && isset($string[$i + 2]) && isset($string[$i + 3])) ? 4 : 1;
    }

    public static function existingEntityLen(string $string, int $i): int
    {
        if (!isset($string[$i]) || '&' !== $string[$i]) {
            return 0;
        }
        if (self::entityMatch($string, $i, '&amp;', 0)) {
            return 5;
        }
        if (self::entityMatch($string, $i, '&lt;', 0)) {
            return 4;
        }
        if (self::entityMatch($string, $i, '&gt;', 0)) {
            return 4;
        }
        if (self::entityMatch($string, $i, '&quot;', 0)) {
            return 6;
        }
        if (self::entityMatch($string, $i, '&#039;', 0)) {
            return 6;
        }
        if (self::entityMatch($string, $i, '&#39;', 0)) {
            return 5;
        }
        if (self::entityMatch($string, $i, '&apos;', 0)) {
            return 6;
        }
        if (self::entityMatch($string, $i, '&euro;', 0)) {
            return 6;
        }

        return self::numericEntityLen($string, $i);
    }

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

    public static function copyBytes(string $string, int $i, int $len): string
    {
        if ($len <= 0) {
            return '';
        }

        return $string[$i].self::copyBytes($string, $i + 1, $len - 1);
    }

    public static function numericEntityLen(string $string, int $i): int
    {
        if (!isset($string[$i]) || '&' !== $string[$i]) {
            return 0;
        }
        if (!isset($string[$i + 1]) || '#' !== $string[$i + 1]) {
            return 0;
        }
        if (!isset($string[$i + 2])) {
            return 0;
        }
        $j = $i + 2;
        if ('x' === $string[$j] || 'X' === $string[$j]) {
            $j = $j + 1;
            if (!isset($string[$j]) || !self::isHexDigit($string[$j])) {
                return 0;
            }

            return self::scanHexEntityEnd($string, $i, $j + 1);
        }
        if (!self::isDigit($string[$j])) {
            return 0;
        }

        return self::scanDecEntityEnd($string, $i, $j + 1);
    }

    public static function scanHexEntityEnd(string $string, int $start, int $j): int
    {
        if (!isset($string[$j])) {
            return 0;
        }
        if (self::isHexDigit($string[$j])) {
            return self::scanHexEntityEnd($string, $start, $j + 1);
        }
        if (';' === $string[$j]) {
            return ($j - $start) + 1;
        }

        return 0;
    }

    public static function scanDecEntityEnd(string $string, int $start, int $j): int
    {
        if (!isset($string[$j])) {
            return 0;
        }
        if (self::isDigit($string[$j])) {
            return self::scanDecEntityEnd($string, $start, $j + 1);
        }
        if (';' === $string[$j]) {
            return ($j - $start) + 1;
        }

        return 0;
    }

    public static function isDigit(string $ch): bool
    {
        return $ch >= '0' && $ch <= '9';
    }

    public static function isHexDigit(string $ch): bool
    {
        return self::isDigit($ch)
            || ($ch >= 'a' && $ch <= 'f')
            || ($ch >= 'A' && $ch <= 'F');
    }
}