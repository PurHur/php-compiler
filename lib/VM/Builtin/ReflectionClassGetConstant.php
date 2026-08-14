<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getConstant() — VM (#6950, php-src ext/reflection/php_reflection.c). */
final class ReflectionClassGetConstant extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getConstant');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (1 args) (#30888)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::getConstant', 1);
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        $constant = VmReflection::stringArg($frame->calledArgs[1], 'ReflectionClass::getConstant() name', 1);
        $decl = VmReflection::findClassConstantDecl($entry, $constant, $ctx);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $decl) {
            $frame->returnVar->bool(false);

            return;
        }
        $value = new Variable();
        if (EnumCaseSupport::tryMaterializeEnumCaseConstantFetch($decl['declaring'], $decl['constLc'], $value)) {
            $frame->returnVar->copyFrom($value);

            return;
        }
        $frame->returnVar->copyFrom($decl['declaring']->constants[$decl['constLc']]);
    }
}
