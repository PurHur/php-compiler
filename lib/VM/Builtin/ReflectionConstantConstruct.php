<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmConstants;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionConstant::__construct($name) or ($class, $name) — VM (#3354, #17341). */
final class ReflectionConstantConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionConstant($frame, $frame->calledArgs[0]);
        $isReflectionConstant = ReflectionSupport::REFLECTION_CONSTANT === strtolower($receiver->class->name);
        if (\count($frame->calledArgs) < 3) {
            if (\count($frame->calledArgs) < 2) {
                throw new \ArgumentCountError(
                    $isReflectionConstant
                        ? 'ReflectionConstant::__construct() expects at least 1 argument, 0 given'
                        : 'ReflectionClassConstant::__construct() expects exactly 2 arguments, 0 given'
                );
            }
            if (!$isReflectionConstant) {
                throw new \ArgumentCountError(
                    'ReflectionClassConstant::__construct() expects exactly 2 arguments, 1 given'
                );
            }
            $ctx = VmReflection::requireContext($frame);
            $constant = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionConstant::__construct() name', 1);
            // php-src: zend_get_constant_ptr — globals only; Class::CONST is ReflectionClassConstant (#23604).
            if (!VmConstants::globalConstantDefined($ctx, $constant)) {
                ReflectionSupport::throwReflectionException(
                    ReflectionSupport::globalConstantNotFoundMessage($constant)
                );
            }
            $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string('');
            $receiver->getProperty(ReflectionSupport::PROP_CONSTANT_NAME)->string($constant);
            $receiver->constructed = true;

            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $entry = VmReflection::resolveClassFromArg($ctx, $frame->calledArgs[1]);
        $constant = VmReflection::stringArg($frame->calledArgs[2], 'ReflectionConstant::__construct() name', 2);
        $decl = VmReflection::findClassConstantDecl($entry, $constant, $ctx);
        if (null === $decl) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::constantNotFoundMessage($entry->name, $constant)
            );
        }
        // Zend stores the declaring class (ce of the constant), not the lookup class (#22581).
        $declaringName = $decl['declaring']->name;
        if ($isReflectionConstant) {
            $receiver->getProperty(ReflectionSupport::PROP_CLASS_NAME)->string($declaringName);
            $receiver->getProperty(ReflectionSupport::PROP_CONSTANT_NAME)->string($constant);
        } else {
            // Zend ReflectionClassConstant::$class / $name (#22503, #22581).
            $receiver->getProperty(ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_CLASS)->string($declaringName);
            $receiver->getProperty(ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_NAME)->string($constant);
        }
        $receiver->constructed = true;
        // Do not touch returnVar: it may alias the `new ReflectionClassConstant()` result slot (#1885, #5954).
    }
}
