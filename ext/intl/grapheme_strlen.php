<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * grapheme_strlen() — grapheme cluster count (php-src ext/intl/grapheme; #5914).
 *
 * VM: {@see VmGrapheme}; JIT: compile-time fold via {@see JitGrapheme}.
 * Z_PARAM_STR null TypeError on 8.4 forward profile (#20694).
 * Reflection / named args: Zend stub `string $string`: `int|false|null` (#27884).
 */
final class grapheme_strlen extends Internal
{
    public function __construct()
    {
        parent::__construct('grapheme_strlen');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'grapheme_strlen', 1);
        // Z_PARAM_STR — Zend 8.4 DEP+coerce on null, not TypeError (#21320, grapheme_string.c).
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, 0, 'grapheme_strlen', 'string');
            $string = $frame->calledArgs[0]->resolveIndirect()->toString();
        } else {
            $string = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'grapheme_strlen', 0, 'string', 'string', false);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmGrapheme::strlen($string);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);

                return;
            }
            $ret->int($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'grapheme_strlen', 1)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $folded = JitGrapheme::tryStrlenFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward (constants + boxed VALUE) (#20694).
        JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'grapheme_strlen', 0, 'string');
        $zparamStrict = $context->callerStrictTypes
            || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile();
        $nullConst = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        if ($nullConst && $zparamStrict) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        // Boxed VALUE: lowerZparamStr already emitted runtime null TypeError (#20694).
        // Full cluster-count runtime remains fold/VM; ok-path placeholder keeps AOT IR valid.
        if (JITVariable::TYPE_VALUE === $args[0]->type && $zparamStrict) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        throw new \LogicException(
            'grapheme_strlen() JIT runtime lowering is deferred; use VM or compile-time literals (#5914)'
        );
    }
}
