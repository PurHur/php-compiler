<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCfg\Op\Type as CfgType;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\VM\Variable;

/** ReflectionParameter::getType() — VM (#3355). */
final class ReflectionParameterGetType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getType');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $declared = self::declaredParamType($ctx, $receiver);
            if (null === $declared) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->copyFrom(ReflectionTypeSupport::buildTypeVariable($ctx, $declared));
            }
        }
    }

    private static function declaredParamType(\PHPCompiler\VM\Context $ctx, ObjectEntry $receiver): ?CfgType
    {
        $methodNameVar = $receiver->getProperty(ReflectionSupport::PROP_METHOD_NAME)->resolveIndirect();
        if (Variable::TYPE_STRING === $methodNameVar->type) {
            $className = ReflectionSupport::classNameFromReflection($receiver);
            $methodName = $methodNameVar->toString();
            $entry = VmReflection::resolveClassEntry($ctx, $className);
            if (null === $entry) {
                return null;
            }
            $methodLc = strtolower($methodName);
            $func = $entry->methods[$methodLc] ?? null;
            if (!$func instanceof PhpFunc) {
                return null;
            }
            $index = ReflectionSupport::paramPositionFromReflection($receiver);

            return $func->block->paramDeclaredTypes[$index] ?? null;
        }

        $func = ReflectionSupport::resolveUserFunction(
            $ctx,
            ReflectionSupport::functionNameFromReflection($receiver)
        );
        $index = ReflectionSupport::paramIndexFromReflection($receiver);

        return $func->block->paramDeclaredTypes[$index] ?? null;
    }
}
