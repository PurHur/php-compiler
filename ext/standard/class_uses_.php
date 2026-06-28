<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * class_uses() — traits used by a class (issue #3119).
 *
 * php-src: ext/standard/spl_functions.c — PHP_FUNCTION(class_uses)
 */
final class class_uses_ extends Internal
{
    public function __construct()
    {
        parent::__construct('class_uses');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('class_uses() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        VmClassHas::requireObjectOrClass($frame->calledArgs[0], 'class_uses', 'object_or_class');
        $autoload = true;
        if ($argc >= 2) {
            $autoload = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1],
                'class_uses',
                2,
                'autoload'
            );
        }
        $entry = VmReflection::resolveClassForClassUses($ctx, $frame->calledArgs[0], $autoload);
        if (null === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmReflection::classUsesArray($entry));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('class_uses() requires one or two arguments in this compiler build');
        }
        $autoload = true;
        if (\count($args) >= 2) {
            if (JITVariable::TYPE_NATIVE_BOOL === $args[1]->type) {
                $autoloadConst = $args[1]->value;
                if ($autoloadConst instanceof \PHPLLVM\Value\ConstantInt) {
                    $autoload = 0 !== $autoloadConst->getValue();
                }
            }
            // AOT literal true/false is often boxed as TYPE_VALUE; object/class operands
            // resolve from the compile-time registry so autoload does not affect #4108.
        }

        return JitClassUses::invoke($context, $args[0], $autoload);
    }
}
