<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * grapheme_levenshtein() — grapheme-cluster edit distance (not shipped by Zend; kept unregistered, #22661).
 *
 * Historical experiment (#6998). Zend/php-src has no stub entry (php/php-src#10180).
 * Internal helper remains at {@see VmGrapheme::levenshtein()} for unit tests.
 */
final class grapheme_levenshtein extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_levenshtein');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'grapheme_levenshtein', 2);
        $string1 = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'grapheme_levenshtein',
            0,
            'string1'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $string2 = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'grapheme_levenshtein',
            1,
            'string2'
        );
        $result = VmGrapheme::levenshtein($string1, $string2);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->int($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'grapheme_levenshtein', 2)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $folded = JitGrapheme::tryLevenshteinFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        JitStringBuiltinArg::lower($context, $args[0], 'grapheme_levenshtein', 0, 'string1');
        JitStringBuiltinArg::lower($context, $args[1], 'grapheme_levenshtein', 1, 'string2');

        throw new \LogicException(
            'grapheme_levenshtein() JIT runtime lowering is deferred; use VM or compile-time literals (#6998)'
        );
    }
}
