<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * class_implements() — interfaces implemented by a class or object (issue #3099).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_implements)
 */
final class class_implements_ extends Internal
{
    public function __construct()
    {
        parent::__construct('class_implements');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('class_implements() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        VmClassHas::requireObjectOrClass($frame->calledArgs[0], 'class_implements', 'object_or_class');
        $autoload = true;
        if ($argc >= 2) {
            $flag = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $flag->type) {
                throw new \LogicException('class_implements() autoload flag must be a boolean in this compiler build');
            }
            $autoload = $flag->toBool();
        }
        $entry = VmReflection::resolveClassForClassImplements($ctx, $frame->calledArgs[0], $autoload);
        if (null === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmReflection::classImplementsArray($entry, $ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('class_implements() requires one or two arguments in this compiler build');
        }
        $autoload = true;
        if (\count($args) >= 2) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[1]->type) {
                throw new \LogicException('class_implements() autoload flag must be a boolean in this compiler build');
            }
            $autoloadConst = $args[1]->value;
            if ($autoloadConst instanceof \PHPLLVM\Value\ConstantInt) {
                $autoload = 0 !== $autoloadConst->getValue();
            }
        }

        return JitClassImplements::invoke($context, $args[0], $autoload);
    }
}
