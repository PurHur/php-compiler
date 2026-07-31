<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * VM preg_replace_callback() — host PCRE for matching, VM callable callbacks (#1177, #4442, #25735).
 */
final class VmPregReplaceCallback
{
    /**
     * @return string|false
     */
    public static function invoke(
        Context $vmContext,
        string $pattern,
        Variable $callback,
        string $subject,
        int $limit = -1,
        ?int &$count = null,
        int $flags = 0,
        ?Frame $scopeFrame = null,
        string $function = 'preg_replace_callback'
    ) {
        if (strlen($pattern) > VmPreg::MAX_PATTERN_BYTES) {
            return false;
        }
        VmCallableInvoke::requireCallable($vmContext, $callback, $function, 2, $scopeFrame);

        $flags = VmPreg::normalizeReplaceCallbackFlags($flags);
        $matchFlags = \PREG_OFFSET_CAPTURE;
        if (0 !== ($flags & StdlibConstants::PREG_UNMATCHED_AS_NULL)) {
            $matchFlags |= \PREG_UNMATCHED_AS_NULL;
        }

        $result = '';
        $offset = 0;
        $len = \strlen($subject);
        $replacements = 0;

        while ($offset < $len) {
            if ($limit >= 0 && $replacements >= $limit) {
                $result .= \substr($subject, $offset);

                break;
            }

            $matches = [];
            $matchCount = \preg_match($pattern, $subject, $matches, $matchFlags, $offset);
            if (false === $matchCount) {
                return false;
            }
            if (0 === $matchCount) {
                $result .= \substr($subject, $offset);

                break;
            }

            $full = $matches[0];
            $matchStart = (int) $full[1];
            $matchText = (string) $full[0];
            $matchLen = \strlen($matchText);
            $result .= \substr($subject, $offset, $matchStart - $offset);

            $vmMatches = VmPreg::callbackMatchesFromOffsetCapture($matches, $flags);
            $replacement = VmCallableInvoke::invokeOne(
                $vmContext,
                $callback,
                $vmMatches,
                $function,
                $scopeFrame
            );
            $replacement = $replacement->resolveIndirect();
            $result .= $vmContext->runtime->vm->coerceVariableToString($replacement);

            ++$replacements;

            $next = $matchStart + $matchLen;
            if ($next <= $offset) {
                return false;
            }
            $offset = $next;
        }

        if (null !== $count) {
            $count = $replacements;
        }

        return $result;
    }
}
