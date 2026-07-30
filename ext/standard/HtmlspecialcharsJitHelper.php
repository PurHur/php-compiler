<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for htmlspecialchars() runtime (#9445, #20487, php-in-PHP).
 *
 * Escape subset mirrors {@see VmString::htmlspecialchars()} / php-src ext/standard/html.c
 * for & < > " '. Self-contained for NestedJIT (#16075).
 *
 * NestedJIT / AOT cannot lower a loop-carried string accumulator when branches
 * compare string offsets (method-return / dynamic args returned empty — #25345).
 * Recurse with `$ch.$rest` / entity.'.'.$rest instead of mutating an accumulator.
 */
final class HtmlspecialcharsJitHelper
{
    public static function htmlspecialchars(string $string, int $flags): string
    {
        return self::escapeFrom($string, $flags, 0);
    }

    /** Public so NestedJIT helper TUs bind the recursive callee (#25345). */
    public static function escapeFrom(string $string, int $flags, int $i): string
    {
        if (!isset($string[$i])) {
            return '';
        }
        $ch = $string[$i];
        $rest = self::escapeFrom($string, $flags, $i + 1);
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & ENT_HTML5);
        if ('&' === $ch) {
            return '&amp;'.$rest;
        }
        if ('<' === $ch) {
            return '&lt;'.$rest;
        }
        if ('>' === $ch) {
            return '&gt;'.$rest;
        }
        if ('"' === $ch) {
            return (($quoteBoth || $quoteDouble) ? '&quot;' : '"').$rest;
        }
        if ("'" === $ch) {
            return ($quoteBoth ? ($entHtml5 ? '&apos;' : '&#039;') : "'").$rest;
        }

        return $ch.$rest;
    }
}
