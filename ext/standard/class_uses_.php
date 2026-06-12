<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
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
        $autoload = true;
        if ($argc >= 2) {
            $autoload = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1],
                'class_uses',
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
        if (\count($args) >= 2) {
            JitBoolArg::lower(
                $context,
                $args[1],
                'class_uses(): Argument #2 ($autoload)'
            );
        }

        return JitClassUses::invoke($context, $args[0], true);
    }
}
