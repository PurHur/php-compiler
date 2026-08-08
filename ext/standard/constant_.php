<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * constant() — resolve a global/user constant by name (issue #3813).
 *
 * php-src: ext/standard/basic_functions.c — zif_constant
 */
final class constant_ extends Internal
{
    private const NAME_TYPE_ERROR =
        'constant(): Argument #1 ($name) must be of type string, %s given';

    public function __construct()
    {
        parent::__construct('constant');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#21964).
        $this->requireExactArgCount($frame, 'constant', 1);
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, 0, 'constant', 'name');
            $name = $frame->calledArgs[0]->resolveIndirect()->toString();
        } else {
            // Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281, ext/standard/basic_functions.c).
            $name = VmString::coerceTrimFamilyStringArg($frame->calledArgs[0], 'constant', 0, 'name');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('constant() requires VM context');
        }
        $value = VmConstants::constantLookup(
            $frame->vmContext,
            $name,
            VmReflection::callerClassLcFromFrame($frame)
        );
        if (null !== $value) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->copyFrom($value);
            }

            return;
        }
        throw new \Error('Undefined constant "'.$name.'"');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'constant', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $nameArg = self::lowerJitNameArg($context, $args[0]);

        return JitConstant::invoke($context, $nameArg);
    }

    private static function lowerJitNameArg(Context $context, JITVariable $arg): JITVariable
    {
        if ($context->callerStrictTypes) {
            if (JITVariable::TYPE_VALUE === $arg->type || JITVariable::TYPE_OBJECT === $arg->type) {
                JitStringBuiltinArg::lowerRequiredString($context, $arg, 'constant', 0, 'name');
            }
            if (JITVariable::TYPE_STRING !== $arg->type) {
                self::emitJitTypeErrorAndAbort($context, self::jitTypeErrorMessage($context, $arg));
            }

            return $arg;
        }
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            // Z_PARAM_STR — soft-null DEP+coerce on 8.4; empty name → Undefined constant (#21281).
            $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, 'constant', 0, 'name');

            return new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $str
            );
        }
        if (JITVariable::TYPE_HASHTABLE === $arg->type || 0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, 'constant', 0, 'name');

            return $arg;
        }

        return JitNativeString::coerce($context, $arg);
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function jitTypeErrorMessage(Context $context, JITVariable $arg): string
    {
        return \sprintf(self::NAME_TYPE_ERROR, JitOperandTypeLabel::givenLabel($context, $arg));
    }
}
