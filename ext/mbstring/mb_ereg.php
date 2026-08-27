<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_ereg() — multibyte POSIX regex match (php-src ext/mbstring/php_mbregex.c; #4635, #33648).
 *
 * JIT/AOT: catchable argc/TypeError (#33648); literal fold via {@see JitMbEregSearch::tryEregFold};
 * runtime via {@see JitMbEreg} → {@see MbEregJitHelper} (#33811); &$regs (#35297).
 */
final class mb_ereg extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_ereg');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_ereg() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $pattern — soft-null DEP then empty ValueError (#30067; php_mbregex.c).
        $pattern = VmMbstring::coerceMbEregPatternArg($frame, 'mb_ereg', 0);
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR $string — null TypeError on PROFILE=8.4 (php_mbregex.c).
        $string = VmString::zparamStrBuiltinArgForFrame($frame, 1, 'mb_ereg', 1, 'string');

        $out = VmMbstring::eregMatch($pattern, $string, false);
        if (!$out['matched'] && null !== VmMbstring::mbEregRegexCompileError($pattern, false)) {
            VmMbstring::warnMbEregRegexFailure($frame, 'mb_ereg', $pattern, false);
        }

        // Always assign $regs when passed — empty array on no-match (php_mbregex.c; #26408).
        if (isset($frame->calledArgs[2])) {
            VmMbstring::writeEregRegistersArg($frame->calledArgs[2]->resolveIndirect(), $out);
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($out): void {
            $ret->bool($out['matched']);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                sprintf('mb_ereg() expects at least 2 arguments, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ereg_argc_cont');

            return self::foldFalse($context);
        }

        // Compile-time null string args under caller strict_types → TypeError (#33648).
        // ExceptionBridge peer: mb_ord array gate / openssl leftovers.
        foreach ([[0, 'pattern'], [1, 'string']] as [$idx, $name]) {
            $isNull = JITVariable::TYPE_NULL === $args[$idx]->type || $args[$idx]->isNullConstant;
            if ($isNull && $context->callerStrictTypes) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    sprintf(
                        'mb_ereg(): Argument #%d ($%s) must be of type string, null given',
                        $idx + 1,
                        $name
                    )
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_ereg_te_cont');

                return self::foldFalse($context);
            }
        }

        $folded = JitMbEregSearch::tryEregFold($context, $args, false);
        if (null !== $folded) {
            return $folded;
        }

        return JitMbEreg::invokeMatch($context, $args, false);
    }

    private static function foldFalse(Context $context): Value
    {
        // Boxed __value__ — matches mb_ord / ExceptionBridge catchable paths (#33648).
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
