<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for htmlspecialchars() runtime (#9445, #20487, php-in-PHP).
 *
 * Escape subset mirrors {@see VmString::htmlspecialchars()} / php-src ext/standard/html.c
 * for & < > " '. Self-contained for NestedJIT (#16075).
 *
 * Full UTF-8 structural validation is deferred: NestedJIT segfaults on the trail-byte
 * helper shape (#22845). Escape accumulation uses `$out .=` (method/helper CONCAT alloca
 * fix in the same issue). Invalid-UTF-8 empty/substitute parity stays on VmString until
 * NestedJIT can lower those checks; MiniWebApp / ASCII titles are covered.
 */
final class HtmlspecialcharsJitHelper
{
    public static function htmlspecialchars(string $string, int $flags): string
    {
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & ENT_HTML5);
        $out = '';
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ('&' === $ch) {
                $out .= '&amp;';
            } elseif ('<' === $ch) {
                $out .= '&lt;';
            } elseif ('>' === $ch) {
                $out .= '&gt;';
            } elseif ('"' === $ch) {
                $out .= ($quoteBoth || $quoteDouble) ? '&quot;' : '"';
            } elseif ("'" === $ch) {
                $out .= $quoteBoth ? ($entHtml5 ? '&apos;' : '&#039;') : "'";
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }
}
