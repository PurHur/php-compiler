<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * php_uname() — operating system identification (ext/standard/info.c parity, issue #3174).
 *
 * Excess argc → Zend ArgumentCountError (#30537; php-src ext/standard/basic_functions.c).
 */
final class php_uname extends Internal
{
    public function __construct()
    {
        parent::__construct('php_uname');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 0..1 — #30537.
        $this->requireArgCountRange($frame, 'php_uname', 0, 1);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $mode = 'a';
        if (1 === $argc) {
            // Z_PARAM_STRING: strict_types TypeError; else soft-null then mode ValueError (#28136).
            $mode = VmString::stringBuiltinArgForFrame(
                $frame,
                0,
                'php_uname',
                0,
                'mode',
                false
            );
        }
        if (VmUnamePure::requiresStrictModeValidation()) {
            VmUnamePure::assertValidMode($mode);
        }
        $frame->returnVar->string(VmInfo::php_uname($mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30537 / peer #30536).
        if (!$this->requireArgCountRangeJit($context, $args, 'php_uname', 0, 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if (!isset($args[0])) {
            return JitInfo::php_uname($context, null);
        }
        $lit = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        if ($context->isUserScriptAot()) {
            // Fold valid letters. Invalid constants under PROFILE≥8.4: pending ValueError
            // (NestedJIT throw from php_unameStrict is not catchable in thin AOT, #28136).
            if (null !== $lit && VmUnamePure::canFoldMode($lit)) {
                $result = VmUnamePure::requiresStrictModeValidation()
                    ? InfoJitHelper::php_unameStrict($lit)
                    : InfoJitHelper::php_uname($lit);

                return JitInfo::emitUserScriptStringLiteral($context, $result);
            }
            if (null !== $lit
                && VmUnamePure::requiresStrictModeValidation()
                && null !== ($message = VmUnamePure::invalidModeValueErrorMessage($lit))
            ) {
                return self::emitConstantModeValueError($context, $message);
            }
        }
        // Z_PARAM_STRING soft-null (DEP+coerce); ValueError from strict helper when PROFILE≥8.4.
        $mode = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[0],
            'php_uname',
            0,
            'mode',
            'string',
            null,
            false
        );

        return JitInfo::php_uname($context, $mode);
    }

    /** Catchable ValueError for compile-time-invalid $mode under PROFILE≥8.4 AOT (#28136). */
    private static function emitConstantModeValueError(Context $context, string $message): Value
    {
        ExceptionBridge::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            TypeErrorRaise::ensureStandaloneBodies($context);
        }
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'ValueError', $message);
            // emitCatchableClassError terminates the block; open a dead insert point for callers.
            BasicBlockHelper::ensureOpenInsertBlock($context, 'php_uname_mode_valueerror_dead');
        } else {
            TypeErrorRaise::emitValueError($context, $message);
            if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
            } else {
                $context->builder->call($context->lookupFunction('abort'));
                $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            }
            BasicBlockHelper::ensureOpenInsertBlock($context, 'php_uname_mode_valueerror_dead');
        }

        return $context->getTypeFromString('__string__*')->constNull();
    }
}
