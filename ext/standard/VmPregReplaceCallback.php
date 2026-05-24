<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * VM preg_replace_callback() — host PCRE for matching, VM user-function callbacks (issue #1177).
 */
final class VmPregReplaceCallback
{
    public static function invoke(
        Context $vmContext,
        string $pattern,
        Func\PHP $callback,
        string $subject
    ): string|false {
        if (strlen($pattern) > VmPreg::MAX_PATTERN_BYTES) {
            return false;
        }

        $result = '';
        $offset = 0;
        $len = \strlen($subject);

        while ($offset < $len) {
            $matches = [];
            $count = \preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE, $offset);
            if (false === $count) {
                return false;
            }
            if (0 === $count) {
                $result .= \substr($subject, $offset);

                break;
            }

            $full = $matches[0];
            $matchStart = $full[1];
            $matchText = $full[0];
            $matchLen = \strlen($matchText);
            $result .= \substr($subject, $offset, $matchStart - $offset);

            $vmMatches = VmJson::import(VmPreg::stripMatchOffsets($matches));
            $replacement = VmUserCall::invokeOne($vmContext, $callback, $vmMatches);
            $replacement = $replacement->resolveIndirect();
            if (Variable::TYPE_STRING !== $replacement->type) {
                throw new \LogicException(
                    'preg_replace_callback() callback must return a string in this compiler build'
                );
            }
            $result .= $replacement->toString();

            $next = $matchStart + $matchLen;
            if ($next <= $offset) {
                return false;
            }
            $offset = $next;
        }

        return $result;
    }
}
