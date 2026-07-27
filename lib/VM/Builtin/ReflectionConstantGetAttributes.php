<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\AttributeRegistry;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getAttributes() — VM read path (#4136, #21255). */
final class ReflectionConstantGetAttributes extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        [$filter, $flags] = ReflectionSupport::getAttributesFilterArgs($frame, 'ReflectionConstant::getAttributes()');
        if (ReflectionSupport::isGlobalReflectionConstant($receiver)) {
            // PHP 8.5+ attributes on file/namespace constants (#23882, TARGET_CONSTANT).
            if (null !== $frame->returnVar) {
                $name = ReflectionSupport::constantNameFromReflection($receiver);
                $allEntries = $ctx->globalConstAttributeEntries[strtolower($name)] ?? [];
                $entries = ReflectionSupport::filterEntriesByName($ctx, $allEntries, $filter, $flags);
                $frame->returnVar->copyFrom(
                    ReflectionSupport::attributesArrayFromEntries(
                        $frame,
                        $entries,
                        AttributeSupport::TARGET_CONSTANT
                    )
                );
            }

            return;
        }
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $constant = ReflectionSupport::constantNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionConstant refers to unknown class in this compiler build');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                AttributeRegistry::constantAttributes($frame, $entry, strtolower($constant), $filter, $flags)
            );
        }
    }
}
