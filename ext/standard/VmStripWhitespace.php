<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\tokenizer\LanguageScanner;
use PHPCompiler\ext\tokenizer\TokenConstantsData;

/**
 * php_strip_whitespace() — zend_strip parity via LanguageScanner (Zend/zend_highlight.c; #3262, #7906).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(php_strip_whitespace)
 *
 * Native LanguageScanner path only — no host builtin delegation (bootstrap/M5, #7906).
 */
final class VmStripWhitespace
{
    /**
     * Read $filename and return stripped PHP source, or '' on failure (Zend parity).
     */
    public static function stripFile(string $filename): string
    {
        $contents = VmFs::fileGetContents($filename);
        if (false === $contents) {
            return '';
        }

        return self::stripSource($contents);
    }

    /**
     * Strip comments and collapse whitespace (zend_strip).
     */
    public static function stripSource(string $code): string
    {
        $tokens = LanguageScanner::tokenize($code);
        $ids = TokenConstantsData::nameToId();
        $tWhitespace = $ids['T_WHITESPACE'];
        $tComment = $ids['T_COMMENT'];
        $tDocComment = $ids['T_DOC_COMMENT'];
        $tEndHeredoc = $ids['T_END_HEREDOC'];

        $out = '';
        $prevSpace = false;
        $tokenCount = \count($tokens);
        $i = 0;
        while ($i < $tokenCount) {
            $token = $tokens[$i];
            if (\is_string($token)) {
                $out = $out.$token;
                $prevSpace = false;
                ++$i;
                continue;
            }

            $id = (int) $token[0];
            $text = $token[1];
            if ($tWhitespace === $id) {
                if (!$prevSpace) {
                    $out = $out.' ';
                    $prevSpace = true;
                }
                ++$i;
                continue;
            }
            if ($tComment === $id || $tDocComment === $id) {
                ++$i;
                continue;
            }
            if ($tEndHeredoc === $id) {
                $out = $out.$text;
                $nextIndex = $i + 1;
                if ($nextIndex < $tokenCount) {
                    $next = $tokens[$nextIndex];
                    if (\is_array($next) && $tWhitespace !== (int) $next[0]) {
                        $out = $out.$next[1];
                        $i = $nextIndex;
                    }
                }
                $out = $out."\n";
                $prevSpace = true;
                ++$i;
                continue;
            }

            $out = $out.$text;
            $prevSpace = false;
            ++$i;
        }

        return $out;
    }
}
