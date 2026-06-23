<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for htmlspecialchars() runtime (#9445, php-in-PHP).
 *
 * Logic mirrors {@see VmString::htmlspecialchars()} UTF-8 subset (php-src ext/standard/html.c).
 * Self-contained so parseAndCompile() does not depend on compiling all of VmString.php.
 */
final class HtmlspecialcharsJitHelper
{
    public static function htmlspecialchars(string $string, int $flags): string
    {
        $quoteBoth = 0 !== ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & ENT_HTML5);
        $out = '';
        $len = \strlen($string);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            switch ($ch) {
                case '&':
                    $out .= '&amp;';
                    break;
                case '<':
                    $out .= '&lt;';
                    break;
                case '>':
                    $out .= '&gt;';
                    break;
                case '"':
                    $out .= ($quoteBoth || $quoteDouble) ? '&quot;' : '"';
                    break;
                case "'":
                    $out .= $quoteBoth ? ($entHtml5 ? '&apos;' : '&#039;') : "'";
                    break;
                default:
                    $out .= $ch;
            }
        }

        return $out;
    }
}
