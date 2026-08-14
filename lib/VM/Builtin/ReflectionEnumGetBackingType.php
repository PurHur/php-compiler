<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\ReflectionTypeSupport;

/** ReflectionEnum::getBackingType() — VM (#9886, ext/reflection/php_reflection.c). */
final class ReflectionEnumGetBackingType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getBackingType');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args)
        $userArgCount = \count($frame->calledArgs) - 1;
        if (0 !== $userArgCount) {
            throw new \ArgumentCountError(\sprintf(
                'ReflectionEnum::getBackingType() expects exactly 0 arguments, %d given',
                $userArgCount
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $receiver = ReflectionSupport::requireReflectionEnum($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $enumName = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $enumName);
        if (null === $entry || !$entry->isEnum) {
            throw new \LogicException('ReflectionEnum refers to unknown enum in this compiler build');
        }
        if (null === $entry->backedType) {
            $frame->returnVar->null();

            return;
        }

        $cfgType = ReflectionTypeSupport::cfgTypeFromLabel($entry->backedType);
        if (null === $cfgType) {
            throw new \LogicException('ReflectionEnum backing type is not reflectable');
        }

        $frame->returnVar->copyFrom(ReflectionTypeSupport::buildTypeVariable($ctx, $cfgType));
    }
}
