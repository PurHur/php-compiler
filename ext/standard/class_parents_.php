<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * class_parents() — parent class chain for a class or object (issue #3159).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30603).
 *
 * php-src: ext/standard/class.c — PHP_FUNCTION(class_parents)
 */
final class class_parents_ extends Internal
{
    public function __construct()
    {
        parent::__construct('class_parents');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..2 (#30603; peer class_implements).
        $this->requireArgCountRange($frame, 'class_parents', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        VmClassHas::requireObjectOrClass($frame->calledArgs[0], 'class_parents', 'object_or_class');
        $autoload = true;
        if ($argc >= 2) {
            $autoload = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1],
                'class_parents',
                2,
                'autoload'
            );
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $arg->type) {
            $frame->returnVar->copyFrom(VmReflection::emptyArray());

            return;
        }
        if (Variable::TYPE_OBJECT === $arg->type && EnumCaseSupport::isEnumCase($arg->toObject())) {
            $frame->returnVar->copyFrom(VmReflection::emptyArray());

            return;
        }
        $entry = VmReflection::resolveClassForClassImplements($ctx, $frame->calledArgs[0], $autoload);
        if (null === $entry) {
            $operand = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_STRING === $operand->type) {
                VmReflection::warnClassOperandNotFound($frame, 'class_parents', $operand->toString(), $autoload);
            }
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmReflection::classParentsArray($entry, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30603).
        if (!$this->requireArgCountRangeJit($context, $args, 'class_parents', 1, 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        $autoload = true;
        if (\count($args) >= 2) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[1]->type) {
                throw new \LogicException('class_parents() autoload flag must be a boolean in this compiler build');
            }
            $autoloadConst = $args[1]->value;
            if ($autoloadConst instanceof \PHPLLVM\Value\ConstantInt) {
                $autoload = 0 !== $autoloadConst->getValue();
            }
        }

        return JitClassParents::invoke($context, $args[0], $autoload);
    }
}
