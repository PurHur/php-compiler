<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_parent_class() — deferred: class extends not implemented (issue #1218).
 */
final class get_parent_class_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_parent_class');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1 || \count($frame->calledArgs) > 2) {
            throw new \LogicException('get_parent_class() requires one or two arguments in this compiler build');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(false);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('get_parent_class() requires one or two arguments in this compiler build');
        }
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(0, false);
    }
}
