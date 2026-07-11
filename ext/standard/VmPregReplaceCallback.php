<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * VM preg_replace_callback() — host PCRE for matching, VM callable callbacks (#1177, #4442).
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
        ?int &$count = null
    ) {
        if (strlen($pattern) > VmPreg::MAX_PATTERN_BYTES) {
            return false;
        }
        if (!VmCallableInvoke::isInvokable($callback)) {
            throw new \TypeError(
                'preg_replace_callback(): Argument #2 ($callback) must be a valid callback, no array or string given'
            );
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
            $matchCount = \preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE, $offset);
            if (false === $matchCount) {
                return false;
            }
            if (0 === $matchCount) {
                $result .= \substr($subject, $offset);

                break;
            }

            $full = $matches[0];
            $matchStart = $full[1];
            $matchText = $full[0];
            $matchLen = \strlen($matchText);
            $result .= \substr($subject, $offset, $matchStart - $offset);

            $vmMatches = VmJson::import(VmPreg::stripMatchOffsets($matches));
            $replacement = VmCallableInvoke::invokeOne($vmContext, $callback, $vmMatches);
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
