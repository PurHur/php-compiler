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
            $flag = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOL !== $flag->type) {
                throw new \LogicException('class_uses() autoload flag must be a boolean in this compiler build');
            }
            $autoload = $flag->toBool();
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
        throw new \LogicException('class_uses() is not supported in JIT in this compiler build; use bin/vm.php');
    }
}
