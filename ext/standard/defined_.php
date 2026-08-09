<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\DefineRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\GlobalIntrospectionNameRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** defined() — whether a user constant is registered (issue #204). */
final class defined_ extends Internal
{
    public function __construct()
    {
        parent::__construct('defined');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#21964).
        $this->requireExactArgCount($frame, 'defined', 1);
        if (null === $frame->vmContext) {
            throw new \LogicException('defined() requires VM context');
        }
        $name = self::vmConstantNameArg($frame);
        $name = VmReflection::normalizeGlobalIntrospectionName($name);
        $defined = VmConstants::constantDefined(
            $frame->vmContext,
            $name,
            VmReflection::callerClassLcFromFrame($frame),
            VmReflection::calledClassLcFromFrame($frame)
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($defined);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'defined', 1)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $i1 = $context->getTypeFromString('int1');
        // Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281); empty name is never defined.
        $nameStr = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'defined',
            0,
            'constant_name'
        );
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            return $i1->constInt(0, false);
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $literal = VmReflection::normalizeGlobalIntrospectionName($literal);
            $callerLc = self::jitCallerClassLc($context);
            $calledLc = self::jitCalledClassLc($context);
            if (null !== $context->runtime->vmContext) {
                try {
                    if (VmConstants::constantDefined(
                        $context->runtime->vmContext,
                        $literal,
                        $callerLc,
                        $calledLc
                    )) {
                        return $i1->constInt(1, false);
                    }
                } catch (\Error $e) {
                    // defined('self::…') outside class — Zend Error (#29455).
                    ErrorRaise::registerDeclarations($context);
                    ErrorRaise::ensureLinked($context);
                    ErrorRaise::emitRaise($context, $e->getMessage());
                    $context->builder->call($context->lookupFunction('abort'));

                    return $i1->constInt(0, false);
                }
            }
            $nameStr = $context->builder->load($context->constantStringFromString($literal));

            return DefineRuntime::emitDefined($context, $nameStr);
        }
        $nameStr = GlobalIntrospectionNameRuntime::normalizeString($context, $nameStr);

        return DefineRuntime::emitDefined($context, $nameStr);
    }

    /** Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281, ext/standard/basic_functions.c). */
    private static function vmConstantNameArg(Frame $frame): string
    {
        return VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'defined',
            0,
            'constant_name'
        );
    }

    /** Class scope for compile-time defined('Class::CONST') fold (#29130). */
    private static function jitCallerClassLc(Context $context): ?string
    {
        if ('' === ($context->scope->className ?? '')) {
            return null;
        }

        return strtolower(ltrim($context->scope->className, '\\'));
    }

    /** Late-static called class for defined('static::CONST') fold (#29455). */
    private static function jitCalledClassLc(Context $context): ?string
    {
        if ('' !== ($context->scope->calledClassName ?? '')) {
            return strtolower(ltrim($context->scope->calledClassName, '\\'));
        }

        return self::jitCallerClassLc($context);
    }
}
