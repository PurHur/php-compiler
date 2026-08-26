<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT runtime helper for htmlentities() UTF-8 entity translation (#10734, #26889, #35050, #35067).
 *
 * Self-contained for NestedJIT thin AOT (#16075 / peer HtmlspecialcharsJitHelper #25345):
 * no VmString call, no loop-carried string accumulator — recurse with char.$rest.
 * php-src: ext/standard/html.c — php_html_entities()
 *
 * NestedJIT constraints for this helper shape:
 * - Bound UTF-8 walks with `\strlen` — `isset($s[$i+1])` is always false (#35050 / peer #35045).
 * - Entity lookup via int `match` on UTF-8 code points — NestedJIT array-key
 *   isset/foreach on multi-byte strings never hits (#35067).
 * - Lead-byte width via one-byte string compares — native `\ord()` collapses width (#35067 /
 *   peer #20452 / #34800).
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
        $len = \strlen($string);
        if ($i >= $len) {
            return '';
        }
        $width = self::utf8CharWidth($string, $i);
        if ($width < 1) {
            return '';
        }
        // ENT_IGNORE: skip illegal UTF-8 bytes (php-src html.c / #32063).
        if (0 !== ($flags & \ENT_IGNORE) && self::utf8ValidWidthAt($string, $i) < 1) {
            return self::escapeFrom($string, $flags, $i + 1, $doubleEncode);
        }
        $char = self::copyBytes($string, $i, $width);
        if (0 !== ($flags & \ENT_DISALLOWED)
            && !self::unicodeCpIsAllowed(self::utf8CodePointAt($string, $i), $flags)) {
            return "\xEF\xBF\xBD".self::escapeFrom($string, $flags, $i + $width, $doubleEncode);
        }
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
        // NestedJIT-safe: int match on code point — not array isset / foreach (#35067).
        $mapped = self::entityForCodePoint(self::utf8CodePointAt($char, 0));

        return '' === $mapped ? $char : $mapped;
    }

    public static function entityForCodePoint(int $cp): string
    {
        return match ($cp) {
            0x26 => '&amp;',
            0x3C => '&lt;',
            0x3E => '&gt;',
            0xA0 => '&nbsp;',
            0xA1 => '&iexcl;',
            0xA2 => '&cent;',
            0xA3 => '&pound;',
            0xA4 => '&curren;',
            0xA5 => '&yen;',
            0xA6 => '&brvbar;',
            0xA7 => '&sect;',
            0xA8 => '&uml;',
            0xA9 => '&copy;',
            0xAA => '&ordf;',
            0xAB => '&laquo;',
            0xAC => '&not;',
            0xAD => '&shy;',
            0xAE => '&reg;',
            0xAF => '&macr;',
            0xB0 => '&deg;',
            0xB1 => '&plusmn;',
            0xB2 => '&sup2;',
            0xB3 => '&sup3;',
            0xB4 => '&acute;',
            0xB5 => '&micro;',
            0xB6 => '&para;',
            0xB7 => '&middot;',
            0xB8 => '&cedil;',
            0xB9 => '&sup1;',
            0xBA => '&ordm;',
            0xBB => '&raquo;',
            0xBC => '&frac14;',
            0xBD => '&frac12;',
            0xBE => '&frac34;',
            0xBF => '&iquest;',
            0xC0 => '&Agrave;',
            0xC1 => '&Aacute;',
            0xC2 => '&Acirc;',
            0xC3 => '&Atilde;',
            0xC4 => '&Auml;',
            0xC5 => '&Aring;',
            0xC6 => '&AElig;',
            0xC7 => '&Ccedil;',
            0xC8 => '&Egrave;',
            0xC9 => '&Eacute;',
            0xCA => '&Ecirc;',
            0xCB => '&Euml;',
            0xCC => '&Igrave;',
            0xCD => '&Iacute;',
            0xCE => '&Icirc;',
            0xCF => '&Iuml;',
            0xD0 => '&ETH;',
            0xD1 => '&Ntilde;',
            0xD2 => '&Ograve;',
            0xD3 => '&Oacute;',
            0xD4 => '&Ocirc;',
            0xD5 => '&Otilde;',
            0xD6 => '&Ouml;',
            0xD7 => '&times;',
            0xD8 => '&Oslash;',
            0xD9 => '&Ugrave;',
            0xDA => '&Uacute;',
            0xDB => '&Ucirc;',
            0xDC => '&Uuml;',
            0xDD => '&Yacute;',
            0xDE => '&THORN;',
            0xDF => '&szlig;',
            0xE0 => '&agrave;',
            0xE1 => '&aacute;',
            0xE2 => '&acirc;',
            0xE3 => '&atilde;',
            0xE4 => '&auml;',
            0xE5 => '&aring;',
            0xE6 => '&aelig;',
            0xE7 => '&ccedil;',
            0xE8 => '&egrave;',
            0xE9 => '&eacute;',
            0xEA => '&ecirc;',
            0xEB => '&euml;',
            0xEC => '&igrave;',
            0xED => '&iacute;',
            0xEE => '&icirc;',
            0xEF => '&iuml;',
            0xF0 => '&eth;',
            0xF1 => '&ntilde;',
            0xF2 => '&ograve;',
            0xF3 => '&oacute;',
            0xF4 => '&ocirc;',
            0xF5 => '&otilde;',
            0xF6 => '&ouml;',
            0xF7 => '&divide;',
            0xF8 => '&oslash;',
            0xF9 => '&ugrave;',
            0xFA => '&uacute;',
            0xFB => '&ucirc;',
            0xFC => '&uuml;',
            0xFD => '&yacute;',
            0xFE => '&thorn;',
            0xFF => '&yuml;',
            0x152 => '&OElig;',
            0x153 => '&oelig;',
            0x160 => '&Scaron;',
            0x161 => '&scaron;',
            0x178 => '&Yuml;',
            0x192 => '&fnof;',
            0x2C6 => '&circ;',
            0x2DC => '&tilde;',
            0x391 => '&Alpha;',
            0x392 => '&Beta;',
            0x393 => '&Gamma;',
            0x394 => '&Delta;',
            0x395 => '&Epsilon;',
            0x396 => '&Zeta;',
            0x397 => '&Eta;',
            0x398 => '&Theta;',
            0x399 => '&Iota;',
            0x39A => '&Kappa;',
            0x39B => '&Lambda;',
            0x39C => '&Mu;',
            0x39D => '&Nu;',
            0x39E => '&Xi;',
            0x39F => '&Omicron;',
            0x3A0 => '&Pi;',
            0x3A1 => '&Rho;',
            0x3A3 => '&Sigma;',
            0x3A4 => '&Tau;',
            0x3A5 => '&Upsilon;',
            0x3A6 => '&Phi;',
            0x3A7 => '&Chi;',
            0x3A8 => '&Psi;',
            0x3A9 => '&Omega;',
            0x3B1 => '&alpha;',
            0x3B2 => '&beta;',
            0x3B3 => '&gamma;',
            0x3B4 => '&delta;',
            0x3B5 => '&epsilon;',
            0x3B6 => '&zeta;',
            0x3B7 => '&eta;',
            0x3B8 => '&theta;',
            0x3B9 => '&iota;',
            0x3BA => '&kappa;',
            0x3BB => '&lambda;',
            0x3BC => '&mu;',
            0x3BD => '&nu;',
            0x3BE => '&xi;',
            0x3BF => '&omicron;',
            0x3C0 => '&pi;',
            0x3C1 => '&rho;',
            0x3C2 => '&sigmaf;',
            0x3C3 => '&sigma;',
            0x3C4 => '&tau;',
            0x3C5 => '&upsilon;',
            0x3C6 => '&phi;',
            0x3C7 => '&chi;',
            0x3C8 => '&psi;',
            0x3C9 => '&omega;',
            0x3D1 => '&thetasym;',
            0x3D2 => '&upsih;',
            0x3D6 => '&piv;',
            0x2002 => '&ensp;',
            0x2003 => '&emsp;',
            0x2009 => '&thinsp;',
            0x200C => '&zwnj;',
            0x200D => '&zwj;',
            0x200E => '&lrm;',
            0x200F => '&rlm;',
            0x2013 => '&ndash;',
            0x2014 => '&mdash;',
            0x2018 => '&lsquo;',
            0x2019 => '&rsquo;',
            0x201A => '&sbquo;',
            0x201C => '&ldquo;',
            0x201D => '&rdquo;',
            0x201E => '&bdquo;',
            0x2020 => '&dagger;',
            0x2021 => '&Dagger;',
            0x2022 => '&bull;',
            0x2026 => '&hellip;',
            0x2030 => '&permil;',
            0x2032 => '&prime;',
            0x2033 => '&Prime;',
            0x2039 => '&lsaquo;',
            0x203A => '&rsaquo;',
            0x203E => '&oline;',
            0x2044 => '&frasl;',
            0x20AC => '&euro;',
            0x2111 => '&image;',
            0x2118 => '&weierp;',
            0x211C => '&real;',
            0x2122 => '&trade;',
            0x2135 => '&alefsym;',
            0x2190 => '&larr;',
            0x2191 => '&uarr;',
            0x2192 => '&rarr;',
            0x2193 => '&darr;',
            0x2194 => '&harr;',
            0x21B5 => '&crarr;',
            0x21D0 => '&lArr;',
            0x21D1 => '&uArr;',
            0x21D2 => '&rArr;',
            0x21D3 => '&dArr;',
            0x21D4 => '&hArr;',
            0x2200 => '&forall;',
            0x2202 => '&part;',
            0x2203 => '&exist;',
            0x2205 => '&empty;',
            0x2207 => '&nabla;',
            0x2208 => '&isin;',
            0x2209 => '&notin;',
            0x220B => '&ni;',
            0x220F => '&prod;',
            0x2211 => '&sum;',
            0x2212 => '&minus;',
            0x2217 => '&lowast;',
            0x221A => '&radic;',
            0x221D => '&prop;',
            0x221E => '&infin;',
            0x2220 => '&ang;',
            0x2227 => '&and;',
            0x2228 => '&or;',
            0x2229 => '&cap;',
            0x222A => '&cup;',
            0x222B => '&int;',
            0x2234 => '&there4;',
            0x223C => '&sim;',
            0x2245 => '&cong;',
            0x2248 => '&asymp;',
            0x2260 => '&ne;',
            0x2261 => '&equiv;',
            0x2264 => '&le;',
            0x2265 => '&ge;',
            0x2282 => '&sub;',
            0x2283 => '&sup;',
            0x2284 => '&nsub;',
            0x2286 => '&sube;',
            0x2287 => '&supe;',
            0x2295 => '&oplus;',
            0x2297 => '&otimes;',
            0x22A5 => '&perp;',
            0x22C5 => '&sdot;',
            0x2308 => '&lceil;',
            0x2309 => '&rceil;',
            0x230A => '&lfloor;',
            0x230B => '&rfloor;',
            0x2329 => '&lang;',
            0x232A => '&rang;',
            0x25CA => '&loz;',
            0x2660 => '&spades;',
            0x2663 => '&clubs;',
            0x2665 => '&hearts;',
            0x2666 => '&diams;',
            default => '',
        };
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

    /**
     * php-src html.c unicode_cp_is_allowed(). ENT_HTML5 == DOC_TYPE_MASK (48).
     * NestedJIT-safe: integer compares only (#32084).
     */
    public static function unicodeCpIsAllowed(int $uniCp, int $flags): bool
    {
        $documentType = $flags & \ENT_HTML5;
        if (\ENT_XML1 === $documentType || \ENT_XHTML === $documentType) {
            return ($uniCp >= 0x20 && $uniCp <= 0xD7FF)
                || (0x0A === $uniCp || 0x09 === $uniCp || 0x0D === $uniCp)
                || ($uniCp >= 0xE000 && $uniCp <= 0x10FFFF && 0xFFFE !== $uniCp && 0xFFFF !== $uniCp);
        }
        if (\ENT_HTML5 === $documentType) {
            return ($uniCp >= 0x20 && $uniCp <= 0x7E)
                || ($uniCp >= 0x09 && $uniCp <= 0x0D && 0x0B !== $uniCp)
                || ($uniCp >= 0xA0 && $uniCp <= 0xD7FF)
                || ($uniCp >= 0xE000 && $uniCp <= 0x10FFFF
                    && (($uniCp & 0xFFFF) < 0xFFFE)
                    && ($uniCp < 0xFDD0 || $uniCp > 0xFDEF));
        }

        return ($uniCp >= 0x20 && $uniCp <= 0x7E)
            || (0x0A === $uniCp || 0x09 === $uniCp || 0x0D === $uniCp)
            || ($uniCp >= 0xA0 && $uniCp <= 0xD7FF)
            || ($uniCp >= 0xE000 && $uniCp <= 0x10FFFF);
    }

    /**
     * NestedJIT-safe byte ordinal — no native `\ord()` (#20452 / #35067).
     * Public so NestedJIT helper TUs bind the callee.
     */
    public static function byteOrd(string $byte): int
    {
        for ($code = 0; $code < 256; ++$code) {
            if ($byte === self::byteAt($code)) {
                return $code;
            }
        }

        return 0;
    }

    /** One-byte literal for {@see byteOrd} (#20452). */
    public static function byteAt(int $code): string
    {
        // Prefer dense match over chr() — NestedJIT zeros native chr (#20452).
        return match ($code) {
            0 => "\0", 1 => "\x01", 2 => "\x02", 3 => "\x03", 4 => "\x04", 5 => "\x05",
            6 => "\x06", 7 => "\x07", 8 => "\x08", 9 => "\x09", 10 => "\x0a", 11 => "\x0b",
            12 => "\x0c", 13 => "\x0d", 14 => "\x0e", 15 => "\x0f", 16 => "\x10", 17 => "\x11",
            18 => "\x12", 19 => "\x13", 20 => "\x14", 21 => "\x15", 22 => "\x16", 23 => "\x17",
            24 => "\x18", 25 => "\x19", 26 => "\x1a", 27 => "\x1b", 28 => "\x1c", 29 => "\x1d",
            30 => "\x1e", 31 => "\x1f", 32 => ' ', 33 => '!', 34 => '"', 35 => '#', 36 => '$',
            37 => '%', 38 => '&', 39 => "'", 40 => '(', 41 => ')', 42 => '*', 43 => '+',
            44 => ',', 45 => '-', 46 => '.', 47 => '/', 48 => '0', 49 => '1', 50 => '2',
            51 => '3', 52 => '4', 53 => '5', 54 => '6', 55 => '7', 56 => '8', 57 => '9',
            58 => ':', 59 => ';', 60 => '<', 61 => '=', 62 => '>', 63 => '?', 64 => '@',
            65 => 'A', 66 => 'B', 67 => 'C', 68 => 'D', 69 => 'E', 70 => 'F', 71 => 'G',
            72 => 'H', 73 => 'I', 74 => 'J', 75 => 'K', 76 => 'L', 77 => 'M', 78 => 'N',
            79 => 'O', 80 => 'P', 81 => 'Q', 82 => 'R', 83 => 'S', 84 => 'T', 85 => 'U',
            86 => 'V', 87 => 'W', 88 => 'X', 89 => 'Y', 90 => 'Z', 91 => '[', 92 => '\\',
            93 => ']', 94 => '^', 95 => '_', 96 => '`', 97 => 'a', 98 => 'b', 99 => 'c',
            100 => 'd', 101 => 'e', 102 => 'f', 103 => 'g', 104 => 'h', 105 => 'i',
            106 => 'j', 107 => 'k', 108 => 'l', 109 => 'm', 110 => 'n', 111 => 'o',
            112 => 'p', 113 => 'q', 114 => 'r', 115 => 's', 116 => 't', 117 => 'u',
            118 => 'v', 119 => 'w', 120 => 'x', 121 => 'y', 122 => 'z', 123 => '{',
            124 => '|', 125 => '}', 126 => '~', 127 => "\x7f",
            128 => "\x80", 129 => "\x81", 130 => "\x82", 131 => "\x83", 132 => "\x84",
            133 => "\x85", 134 => "\x86", 135 => "\x87", 136 => "\x88", 137 => "\x89",
            138 => "\x8a", 139 => "\x8b", 140 => "\x8c", 141 => "\x8d", 142 => "\x8e",
            143 => "\x8f", 144 => "\x90", 145 => "\x91", 146 => "\x92", 147 => "\x93",
            148 => "\x94", 149 => "\x95", 150 => "\x96", 151 => "\x97", 152 => "\x98",
            153 => "\x99", 154 => "\x9a", 155 => "\x9b", 156 => "\x9c", 157 => "\x9d",
            158 => "\x9e", 159 => "\x9f", 160 => "\xa0", 161 => "\xa1", 162 => "\xa2",
            163 => "\xa3", 164 => "\xa4", 165 => "\xa5", 166 => "\xa6", 167 => "\xa7",
            168 => "\xa8", 169 => "\xa9", 170 => "\xaa", 171 => "\xab", 172 => "\xac",
            173 => "\xad", 174 => "\xae", 175 => "\xaf", 176 => "\xb0", 177 => "\xb1",
            178 => "\xb2", 179 => "\xb3", 180 => "\xb4", 181 => "\xb5", 182 => "\xb6",
            183 => "\xb7", 184 => "\xb8", 185 => "\xb9", 186 => "\xba", 187 => "\xbb",
            188 => "\xbc", 189 => "\xbd", 190 => "\xbe", 191 => "\xbf", 192 => "\xc0",
            193 => "\xc1", 194 => "\xc2", 195 => "\xc3", 196 => "\xc4", 197 => "\xc5",
            198 => "\xc6", 199 => "\xc7", 200 => "\xc8", 201 => "\xc9", 202 => "\xca",
            203 => "\xcb", 204 => "\xcc", 205 => "\xcd", 206 => "\xce", 207 => "\xcf",
            208 => "\xd0", 209 => "\xd1", 210 => "\xd2", 211 => "\xd3", 212 => "\xd4",
            213 => "\xd5", 214 => "\xd6", 215 => "\xd7", 216 => "\xd8", 217 => "\xd9",
            218 => "\xda", 219 => "\xdb", 220 => "\xdc", 221 => "\xdd", 222 => "\xde",
            223 => "\xdf", 224 => "\xe0", 225 => "\xe1", 226 => "\xe2", 227 => "\xe3",
            228 => "\xe4", 229 => "\xe5", 230 => "\xe6", 231 => "\xe7", 232 => "\xe8",
            233 => "\xe9", 234 => "\xea", 235 => "\xeb", 236 => "\xec", 237 => "\xed",
            238 => "\xee", 239 => "\xef", 240 => "\xf0", 241 => "\xf1", 242 => "\xf2",
            243 => "\xf3", 244 => "\xf4", 245 => "\xf5", 246 => "\xf6", 247 => "\xf7",
            248 => "\xf8", 249 => "\xf9", 250 => "\xfa", 251 => "\xfb", 252 => "\xfc",
            253 => "\xfd", 254 => "\xfe", 255 => "\xff",
            default => "\0",
        };
    }

    /** UTF-8 scalar at $i (php-src get_next_char). NestedJIT-safe: strlen + byteOrd (#35067). */
    public static function utf8CodePointAt(string $string, int $i): int
    {
        $len = \strlen($string);
        if ($i >= $len) {
            return 0;
        }
        $byte = self::byteOrd($string[$i]);
        if ($byte < 0x80) {
            return $byte;
        }
        if (($byte & 0xE0) === 0xC0 && ($i + 1) < $len) {
            return (($byte & 0x1F) << 6) | (self::byteOrd($string[$i + 1]) & 0x3F);
        }
        if (($byte & 0xF0) === 0xE0 && ($i + 2) < $len) {
            return (($byte & 0x0F) << 12)
                | ((self::byteOrd($string[$i + 1]) & 0x3F) << 6)
                | (self::byteOrd($string[$i + 2]) & 0x3F);
        }
        if (($byte & 0xF8) === 0xF0 && ($i + 3) < $len) {
            return (($byte & 0x07) << 18)
                | ((self::byteOrd($string[$i + 1]) & 0x3F) << 12)
                | ((self::byteOrd($string[$i + 2]) & 0x3F) << 6)
                | (self::byteOrd($string[$i + 3]) & 0x3F);
        }

        return $byte;
    }

    /**
     * Lead-byte width without `\ord()` — one-byte string compares (#35067).
     */
    public static function utf8CharWidth(string $string, int $i): int
    {
        $len = \strlen($string);
        if ($i >= $len) {
            return 0;
        }
        $b = $string[$i];
        if ($b <= "\x7F") {
            return 1;
        }
        if ($b <= "\xDF") {
            return ($i + 1) < $len ? 2 : 1;
        }
        if ($b <= "\xEF") {
            return ($i + 2) < $len ? 3 : 1;
        }

        return ($i + 3) < $len ? 4 : 1;
    }

    /**
     * Width of a well-formed UTF-8 sequence at $i, or 0 if illegal (php-src html.c
     * get_next_char). NestedJIT-safe: strlen + byteOrd (#35050 / #35067).
     */
    public static function utf8ValidWidthAt(string $string, int $i): int
    {
        $len = \strlen($string);
        if ($i >= $len) {
            return 0;
        }
        $byte = self::byteOrd($string[$i]);
        if ($byte < 0x80) {
            return 1;
        }
        if (($byte & 0xE0) === 0xC0) {
            if (($i + 1) >= $len) {
                return 0;
            }
            $next = self::byteOrd($string[$i + 1]);
            if (($next & 0xC0) !== 0x80) {
                return 0;
            }
            $cp = (($byte & 0x1F) << 6) | ($next & 0x3F);
            if ($cp < 0x80) {
                return 0;
            }

            return 2;
        }
        if (($byte & 0xF0) === 0xE0) {
            if (($i + 2) >= $len) {
                return 0;
            }
            $n1 = self::byteOrd($string[$i + 1]);
            $n2 = self::byteOrd($string[$i + 2]);
            if (($n1 & 0xC0) !== 0x80 || ($n2 & 0xC0) !== 0x80) {
                return 0;
            }
            $cp = (($byte & 0x0F) << 12) | (($n1 & 0x3F) << 6) | ($n2 & 0x3F);
            if ($cp < 0x800 || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
                return 0;
            }

            return 3;
        }
        if (($byte & 0xF8) === 0xF0) {
            if (($i + 3) >= $len) {
                return 0;
            }
            $n1 = self::byteOrd($string[$i + 1]);
            $n2 = self::byteOrd($string[$i + 2]);
            $n3 = self::byteOrd($string[$i + 3]);
            if (($n1 & 0xC0) !== 0x80 || ($n2 & 0xC0) !== 0x80 || ($n3 & 0xC0) !== 0x80) {
                return 0;
            }
            $cp = (($byte & 0x07) << 18) | (($n1 & 0x3F) << 12) | (($n2 & 0x3F) << 6) | ($n3 & 0x3F);
            if ($cp < 0x10000) {
                return 0;
            }

            return 4;
        }

        return 0;
    }

    public static function existingEntityLen(string $string, int $i): int
    {
        $len = \strlen($string);
        if ($i >= $len || '&' !== $string[$i]) {
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
        $entityLen = \strlen($entity);
        if ($j >= $entityLen) {
            return true;
        }
        $len = \strlen($string);
        if (($i + $j) >= $len || $string[$i + $j] !== $entity[$j]) {
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
        $len = \strlen($string);
        if ($i >= $len || '&' !== $string[$i]) {
            return 0;
        }
        if (($i + 1) >= $len || '#' !== $string[$i + 1]) {
            return 0;
        }
        if (($i + 2) >= $len) {
            return 0;
        }
        $j = $i + 2;
        if ('x' === $string[$j] || 'X' === $string[$j]) {
            $j = $j + 1;
            if ($j >= $len || !self::isHexDigit($string[$j])) {
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
        $len = \strlen($string);
        if ($j >= $len) {
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
        $len = \strlen($string);
        if ($j >= $len) {
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