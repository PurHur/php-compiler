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
 * NestedJIT constraints:
 * - Bound walks with `\strlen` — `isset($s[$i+1])` is always false (#35050 / #35045 / #35039).
 * - Lead-byte width via `match` on `"\\xNN"` literals — native `\ord()` of high bytes is
 *   signed/wrong under NestedJIT, so `$b < 0x80` collapsed every multi-byte lead to width 1.
 * - Entity map via `match ($cp)` on integer codepoints + byteOrd tables — multi-byte string
 *   map keys miss under helper-runtime NestedJIT emit (#35067).
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
        // Helper-runtime NestedJIT emit may index UTF-8 by codepoint: $string[$i] is already
        // the full character (strlen>1). Byte-oriented NestedJIT yields one byte per index (#35067).
        $piece = $string[$i];
        $pieceLen = \strlen($piece);
        if ($pieceLen > 1) {
            if (0 !== ($flags & \ENT_IGNORE) && self::utf8ValidWidthAt($piece, 0) < 1) {
                return self::escapeFrom($string, $flags, $i + 1, $doubleEncode);
            }
            $width = self::utf8LeadWidth($piece[0]);
            if (0 !== ($flags & \ENT_DISALLOWED)
                && !self::unicodeCpIsAllowed(self::utf8CodePointFromChar($piece, $width), $flags)) {
                return "\xEF\xBF\xBD".self::escapeFrom($string, $flags, $i + 1, $doubleEncode);
            }
            $rest = self::escapeFrom($string, $flags, $i + 1, $doubleEncode);

            return self::lookupEntity($piece, $flags, $width).$rest;
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
        $mapped = self::lookupEntity($char, $flags, $width);

        return $mapped.$rest;
    }

    public static function lookupEntity(string $char, int $flags, int $width = 1): string
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
        $cp = self::utf8CodePointFromChar($char, $width);
        $mapped = self::entityNameForCodePoint($cp);
        if ('' !== $mapped) {
            return $mapped;
        }

        return $char;
    }

    /**
     * Decode a UTF-8 character to a codepoint via byteOrd (#35067).
     * Pass {@see utf8CharWidth} — do not use strlen($char) (NestedJIT may report 1 for multi-byte).
     */
    public static function utf8CodePointFromChar(string $char, int $width): int
    {
        if ($width < 1) {
            return 0;
        }
        $b0 = self::byteOrd($char[0]) & 0xFF;
        if (1 === $width) {
            return $b0;
        }
        if (2 === $width) {
            $b1 = self::byteOrd($char[1]) & 0xFF;

            return (($b0 & 0x1F) << 6) | ($b1 & 0x3F);
        }
        if (3 === $width) {
            $b1 = self::byteOrd($char[1]) & 0xFF;
            $b2 = self::byteOrd($char[2]) & 0xFF;

            return (($b0 & 0x0F) << 12) | (($b1 & 0x3F) << 6) | ($b2 & 0x3F);
        }
        if (4 === $width) {
            $b1 = self::byteOrd($char[1]) & 0xFF;
            $b2 = self::byteOrd($char[2]) & 0xFF;
            $b3 = self::byteOrd($char[3]) & 0xFF;

            return (($b0 & 0x07) << 18) | (($b1 & 0x3F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
        }

        return $b0;
    }

    /**
     * NestedJIT-safe HTML_ENTITIES map — `match` on integer codepoints (#35067).
     * Multi-byte string keys miss under helper-runtime NestedJIT emit;
     * 1-byte and int arms work (peer Base64JitHelper byte tables).
     */
    public static function entityNameForCodePoint(int $cp): string
    {
        return match ($cp) {
            38 => '&amp;',
            60 => '&lt;',
            62 => '&gt;',
            160 => '&nbsp;',
            161 => '&iexcl;',
            162 => '&cent;',
            163 => '&pound;',
            164 => '&curren;',
            165 => '&yen;',
            166 => '&brvbar;',
            167 => '&sect;',
            168 => '&uml;',
            169 => '&copy;',
            170 => '&ordf;',
            171 => '&laquo;',
            172 => '&not;',
            173 => '&shy;',
            174 => '&reg;',
            175 => '&macr;',
            176 => '&deg;',
            177 => '&plusmn;',
            178 => '&sup2;',
            179 => '&sup3;',
            180 => '&acute;',
            181 => '&micro;',
            182 => '&para;',
            183 => '&middot;',
            184 => '&cedil;',
            185 => '&sup1;',
            186 => '&ordm;',
            187 => '&raquo;',
            188 => '&frac14;',
            189 => '&frac12;',
            190 => '&frac34;',
            191 => '&iquest;',
            192 => '&Agrave;',
            193 => '&Aacute;',
            194 => '&Acirc;',
            195 => '&Atilde;',
            196 => '&Auml;',
            197 => '&Aring;',
            198 => '&AElig;',
            199 => '&Ccedil;',
            200 => '&Egrave;',
            201 => '&Eacute;',
            202 => '&Ecirc;',
            203 => '&Euml;',
            204 => '&Igrave;',
            205 => '&Iacute;',
            206 => '&Icirc;',
            207 => '&Iuml;',
            208 => '&ETH;',
            209 => '&Ntilde;',
            210 => '&Ograve;',
            211 => '&Oacute;',
            212 => '&Ocirc;',
            213 => '&Otilde;',
            214 => '&Ouml;',
            215 => '&times;',
            216 => '&Oslash;',
            217 => '&Ugrave;',
            218 => '&Uacute;',
            219 => '&Ucirc;',
            220 => '&Uuml;',
            221 => '&Yacute;',
            222 => '&THORN;',
            223 => '&szlig;',
            224 => '&agrave;',
            225 => '&aacute;',
            226 => '&acirc;',
            227 => '&atilde;',
            228 => '&auml;',
            229 => '&aring;',
            230 => '&aelig;',
            231 => '&ccedil;',
            232 => '&egrave;',
            233 => '&eacute;',
            234 => '&ecirc;',
            235 => '&euml;',
            236 => '&igrave;',
            237 => '&iacute;',
            238 => '&icirc;',
            239 => '&iuml;',
            240 => '&eth;',
            241 => '&ntilde;',
            242 => '&ograve;',
            243 => '&oacute;',
            244 => '&ocirc;',
            245 => '&otilde;',
            246 => '&ouml;',
            247 => '&divide;',
            248 => '&oslash;',
            249 => '&ugrave;',
            250 => '&uacute;',
            251 => '&ucirc;',
            252 => '&uuml;',
            253 => '&yacute;',
            254 => '&thorn;',
            255 => '&yuml;',
            338 => '&OElig;',
            339 => '&oelig;',
            352 => '&Scaron;',
            353 => '&scaron;',
            376 => '&Yuml;',
            402 => '&fnof;',
            710 => '&circ;',
            732 => '&tilde;',
            913 => '&Alpha;',
            914 => '&Beta;',
            915 => '&Gamma;',
            916 => '&Delta;',
            917 => '&Epsilon;',
            918 => '&Zeta;',
            919 => '&Eta;',
            920 => '&Theta;',
            921 => '&Iota;',
            922 => '&Kappa;',
            923 => '&Lambda;',
            924 => '&Mu;',
            925 => '&Nu;',
            926 => '&Xi;',
            927 => '&Omicron;',
            928 => '&Pi;',
            929 => '&Rho;',
            931 => '&Sigma;',
            932 => '&Tau;',
            933 => '&Upsilon;',
            934 => '&Phi;',
            935 => '&Chi;',
            936 => '&Psi;',
            937 => '&Omega;',
            945 => '&alpha;',
            946 => '&beta;',
            947 => '&gamma;',
            948 => '&delta;',
            949 => '&epsilon;',
            950 => '&zeta;',
            951 => '&eta;',
            952 => '&theta;',
            953 => '&iota;',
            954 => '&kappa;',
            955 => '&lambda;',
            956 => '&mu;',
            957 => '&nu;',
            958 => '&xi;',
            959 => '&omicron;',
            960 => '&pi;',
            961 => '&rho;',
            962 => '&sigmaf;',
            963 => '&sigma;',
            964 => '&tau;',
            965 => '&upsilon;',
            966 => '&phi;',
            967 => '&chi;',
            968 => '&psi;',
            969 => '&omega;',
            977 => '&thetasym;',
            978 => '&upsih;',
            982 => '&piv;',
            8194 => '&ensp;',
            8195 => '&emsp;',
            8201 => '&thinsp;',
            8204 => '&zwnj;',
            8205 => '&zwj;',
            8206 => '&lrm;',
            8207 => '&rlm;',
            8211 => '&ndash;',
            8212 => '&mdash;',
            8216 => '&lsquo;',
            8217 => '&rsquo;',
            8218 => '&sbquo;',
            8220 => '&ldquo;',
            8221 => '&rdquo;',
            8222 => '&bdquo;',
            8224 => '&dagger;',
            8225 => '&Dagger;',
            8226 => '&bull;',
            8230 => '&hellip;',
            8240 => '&permil;',
            8242 => '&prime;',
            8243 => '&Prime;',
            8249 => '&lsaquo;',
            8250 => '&rsaquo;',
            8254 => '&oline;',
            8260 => '&frasl;',
            8364 => '&euro;',
            8465 => '&image;',
            8472 => '&weierp;',
            8476 => '&real;',
            8482 => '&trade;',
            8501 => '&alefsym;',
            8592 => '&larr;',
            8593 => '&uarr;',
            8594 => '&rarr;',
            8595 => '&darr;',
            8596 => '&harr;',
            8629 => '&crarr;',
            8656 => '&lArr;',
            8657 => '&uArr;',
            8658 => '&rArr;',
            8659 => '&dArr;',
            8660 => '&hArr;',
            8704 => '&forall;',
            8706 => '&part;',
            8707 => '&exist;',
            8709 => '&empty;',
            8711 => '&nabla;',
            8712 => '&isin;',
            8713 => '&notin;',
            8715 => '&ni;',
            8719 => '&prod;',
            8721 => '&sum;',
            8722 => '&minus;',
            8727 => '&lowast;',
            8730 => '&radic;',
            8733 => '&prop;',
            8734 => '&infin;',
            8736 => '&ang;',
            8743 => '&and;',
            8744 => '&or;',
            8745 => '&cap;',
            8746 => '&cup;',
            8747 => '&int;',
            8756 => '&there4;',
            8764 => '&sim;',
            8773 => '&cong;',
            8776 => '&asymp;',
            8800 => '&ne;',
            8801 => '&equiv;',
            8804 => '&le;',
            8805 => '&ge;',
            8834 => '&sub;',
            8835 => '&sup;',
            8836 => '&nsub;',
            8838 => '&sube;',
            8839 => '&supe;',
            8853 => '&oplus;',
            8855 => '&otimes;',
            8869 => '&perp;',
            8901 => '&sdot;',
            8968 => '&lceil;',
            8969 => '&rceil;',
            8970 => '&lfloor;',
            8971 => '&rfloor;',
            9001 => '&lang;',
            9002 => '&rang;',
            9674 => '&loz;',
            9824 => '&spades;',
            9827 => '&clubs;',
            9829 => '&hearts;',
            9830 => '&diams;',
            default => '',
        };
    }

    /**
     * HTML_ENTITIES core map (php-src html.c ENT_QUOTES) without quote keys —
     * quotes handled in {@see lookupEntity} from flags. Kept for Zend-side tests;
     * NestedJIT uses {@see entityFromUtf8Char} (#35067).
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

    /** UTF-8 scalar at $i (php-src get_next_char). NestedJIT-safe: strlen + byteOrd (#35067). */
    public static function utf8CodePointAt(string $string, int $i): int
    {
        $len = \strlen($string);
        if ($i >= $len) {
            return 0;
        }
        $w = self::utf8LeadWidth($string[$i]);
        if (($i + $w - 1) >= $len) {
            return self::byteOrd($string[$i]);
        }

        return self::utf8CodePointFromChar(self::copyBytes($string, $i, $w), $w);
    }

    /** NestedJIT-safe unsigned byte value — match on 1-byte literals (peer Base64JitHelper #35067). */
    public static function byteOrd(string $byte): int
    {
        return match ($byte) {
            "\x00" => 0,
            "\x01" => 1,
            "\x02" => 2,
            "\x03" => 3,
            "\x04" => 4,
            "\x05" => 5,
            "\x06" => 6,
            "\x07" => 7,
            "\x08" => 8,
            "\x09" => 9,
            "\x0A" => 10,
            "\x0B" => 11,
            "\x0C" => 12,
            "\x0D" => 13,
            "\x0E" => 14,
            "\x0F" => 15,
            "\x10" => 16,
            "\x11" => 17,
            "\x12" => 18,
            "\x13" => 19,
            "\x14" => 20,
            "\x15" => 21,
            "\x16" => 22,
            "\x17" => 23,
            "\x18" => 24,
            "\x19" => 25,
            "\x1A" => 26,
            "\x1B" => 27,
            "\x1C" => 28,
            "\x1D" => 29,
            "\x1E" => 30,
            "\x1F" => 31,
            "\x20" => 32,
            "\x21" => 33,
            "\x22" => 34,
            "\x23" => 35,
            "\x24" => 36,
            "\x25" => 37,
            "\x26" => 38,
            "\x27" => 39,
            "\x28" => 40,
            "\x29" => 41,
            "\x2A" => 42,
            "\x2B" => 43,
            "\x2C" => 44,
            "\x2D" => 45,
            "\x2E" => 46,
            "\x2F" => 47,
            "\x30" => 48,
            "\x31" => 49,
            "\x32" => 50,
            "\x33" => 51,
            "\x34" => 52,
            "\x35" => 53,
            "\x36" => 54,
            "\x37" => 55,
            "\x38" => 56,
            "\x39" => 57,
            "\x3A" => 58,
            "\x3B" => 59,
            "\x3C" => 60,
            "\x3D" => 61,
            "\x3E" => 62,
            "\x3F" => 63,
            "\x40" => 64,
            "\x41" => 65,
            "\x42" => 66,
            "\x43" => 67,
            "\x44" => 68,
            "\x45" => 69,
            "\x46" => 70,
            "\x47" => 71,
            "\x48" => 72,
            "\x49" => 73,
            "\x4A" => 74,
            "\x4B" => 75,
            "\x4C" => 76,
            "\x4D" => 77,
            "\x4E" => 78,
            "\x4F" => 79,
            "\x50" => 80,
            "\x51" => 81,
            "\x52" => 82,
            "\x53" => 83,
            "\x54" => 84,
            "\x55" => 85,
            "\x56" => 86,
            "\x57" => 87,
            "\x58" => 88,
            "\x59" => 89,
            "\x5A" => 90,
            "\x5B" => 91,
            "\x5C" => 92,
            "\x5D" => 93,
            "\x5E" => 94,
            "\x5F" => 95,
            "\x60" => 96,
            "\x61" => 97,
            "\x62" => 98,
            "\x63" => 99,
            "\x64" => 100,
            "\x65" => 101,
            "\x66" => 102,
            "\x67" => 103,
            "\x68" => 104,
            "\x69" => 105,
            "\x6A" => 106,
            "\x6B" => 107,
            "\x6C" => 108,
            "\x6D" => 109,
            "\x6E" => 110,
            "\x6F" => 111,
            "\x70" => 112,
            "\x71" => 113,
            "\x72" => 114,
            "\x73" => 115,
            "\x74" => 116,
            "\x75" => 117,
            "\x76" => 118,
            "\x77" => 119,
            "\x78" => 120,
            "\x79" => 121,
            "\x7A" => 122,
            "\x7B" => 123,
            "\x7C" => 124,
            "\x7D" => 125,
            "\x7E" => 126,
            "\x7F" => 127,
            "\x80" => 128,
            "\x81" => 129,
            "\x82" => 130,
            "\x83" => 131,
            "\x84" => 132,
            "\x85" => 133,
            "\x86" => 134,
            "\x87" => 135,
            "\x88" => 136,
            "\x89" => 137,
            "\x8A" => 138,
            "\x8B" => 139,
            "\x8C" => 140,
            "\x8D" => 141,
            "\x8E" => 142,
            "\x8F" => 143,
            "\x90" => 144,
            "\x91" => 145,
            "\x92" => 146,
            "\x93" => 147,
            "\x94" => 148,
            "\x95" => 149,
            "\x96" => 150,
            "\x97" => 151,
            "\x98" => 152,
            "\x99" => 153,
            "\x9A" => 154,
            "\x9B" => 155,
            "\x9C" => 156,
            "\x9D" => 157,
            "\x9E" => 158,
            "\x9F" => 159,
            "\xA0" => 160,
            "\xA1" => 161,
            "\xA2" => 162,
            "\xA3" => 163,
            "\xA4" => 164,
            "\xA5" => 165,
            "\xA6" => 166,
            "\xA7" => 167,
            "\xA8" => 168,
            "\xA9" => 169,
            "\xAA" => 170,
            "\xAB" => 171,
            "\xAC" => 172,
            "\xAD" => 173,
            "\xAE" => 174,
            "\xAF" => 175,
            "\xB0" => 176,
            "\xB1" => 177,
            "\xB2" => 178,
            "\xB3" => 179,
            "\xB4" => 180,
            "\xB5" => 181,
            "\xB6" => 182,
            "\xB7" => 183,
            "\xB8" => 184,
            "\xB9" => 185,
            "\xBA" => 186,
            "\xBB" => 187,
            "\xBC" => 188,
            "\xBD" => 189,
            "\xBE" => 190,
            "\xBF" => 191,
            "\xC0" => 192,
            "\xC1" => 193,
            "\xC2" => 194,
            "\xC3" => 195,
            "\xC4" => 196,
            "\xC5" => 197,
            "\xC6" => 198,
            "\xC7" => 199,
            "\xC8" => 200,
            "\xC9" => 201,
            "\xCA" => 202,
            "\xCB" => 203,
            "\xCC" => 204,
            "\xCD" => 205,
            "\xCE" => 206,
            "\xCF" => 207,
            "\xD0" => 208,
            "\xD1" => 209,
            "\xD2" => 210,
            "\xD3" => 211,
            "\xD4" => 212,
            "\xD5" => 213,
            "\xD6" => 214,
            "\xD7" => 215,
            "\xD8" => 216,
            "\xD9" => 217,
            "\xDA" => 218,
            "\xDB" => 219,
            "\xDC" => 220,
            "\xDD" => 221,
            "\xDE" => 222,
            "\xDF" => 223,
            "\xE0" => 224,
            "\xE1" => 225,
            "\xE2" => 226,
            "\xE3" => 227,
            "\xE4" => 228,
            "\xE5" => 229,
            "\xE6" => 230,
            "\xE7" => 231,
            "\xE8" => 232,
            "\xE9" => 233,
            "\xEA" => 234,
            "\xEB" => 235,
            "\xEC" => 236,
            "\xED" => 237,
            "\xEE" => 238,
            "\xEF" => 239,
            "\xF0" => 240,
            "\xF1" => 241,
            "\xF2" => 242,
            "\xF3" => 243,
            "\xF4" => 244,
            "\xF5" => 245,
            "\xF6" => 246,
            "\xF7" => 247,
            "\xF8" => 248,
            "\xF9" => 249,
            "\xFA" => 250,
            "\xFB" => 251,
            "\xFC" => 252,
            "\xFD" => 253,
            "\xFE" => 254,
            "\xFF" => 255,
            default => 0,
        };
    }

    public static function utf8CharWidth(string $string, int $i): int
    {
        $len = \strlen($string);
        if ($i >= $len) {
            return 0;
        }
        $w = self::utf8LeadWidth($string[$i]);
        if (1 === $w) {
            return 1;
        }
        if (($i + $w - 1) >= $len) {
            return 1;
        }

        return $w;
    }

    /**
     * UTF-8 lead-byte width without native ord() (#35067). Uses {@see byteOrd} (peer Base64).
     * Mask with 0xFF — NestedJIT may keep match() results as signed i8 (#35067).
     */
    public static function utf8LeadWidth(string $lead): int
    {
        $b = self::byteOrd($lead[0]) & 0xFF;
        if ($b < 0x80) {
            return 1;
        }
        if ($b <= 0xDF) {
            return 2;
        }
        if ($b <= 0xEF) {
            return 3;
        }
        if ($b <= 0xF4) {
            return 4;
        }

        return 1;
    }

    /**
     * Width of a well-formed UTF-8 sequence at $i, or 0 if illegal (php-src html.c
     * get_next_char). NestedJIT-safe: strlen bounds (#35050 / was isset #32063).
     */
    public static function utf8ValidWidthAt(string $string, int $i): int
    {
        $len = \strlen($string);
        if ($i >= $len) {
            return 0;
        }
        $byte = self::byteOrd($string[$i][0]) & 0xFF;
        if ($byte < 0x80) {
            return 1;
        }
        if (($byte & 0xE0) === 0xC0) {
            if (($i + 1) >= $len) {
                return 0;
            }
            $next = self::byteOrd($string[$i + 1][0]);
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
            $n1 = self::byteOrd($string[$i + 1][0]);
            $n2 = self::byteOrd($string[$i + 2][0]);
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
            $n1 = self::byteOrd($string[$i + 1][0]);
            $n2 = self::byteOrd($string[$i + 2][0]);
            $n3 = self::byteOrd($string[$i + 3][0]);
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