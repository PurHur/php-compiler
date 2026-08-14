<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmConstants;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::getValue() — VM (#3354, #17341). */
final class ReflectionConstantGetValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getValue');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        // php-src: zim_ReflectionClassConstant_getValue / ReflectionConstant — 0 args (#30896)
        $this->requireExactUserArgCount($frame, $receiver->class->name.'::getValue', 0);
        $ctx = VmReflection::requireContext($frame);
        $constant = ReflectionSupport::constantNameFromReflection($receiver);
        if (ReflectionSupport::isGlobalReflectionConstant($receiver)) {
            // Match ctor: globals only — never Class::CONST via constant() (#23604).
            $value = VmConstants::globalConstantLookup($ctx, $constant);
            if (null === $value) {
                ReflectionSupport::throwReflectionException(
                    ReflectionSupport::globalConstantNotFoundMessage($constant)
                );
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->copyFrom($value);
            }

            return;
        }
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionConstant refers to unknown class in this compiler build');
        }
        $key = VmReflection::findClassConstantKey($entry, $constant, $ctx);
        if (null === $key) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::constantNotFoundMessage($className, $constant)
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($entry->constants[$key]);
        }
    }
}
