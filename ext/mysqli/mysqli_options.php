<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mysqli_options() / mysqli_set_opt() — php-src ext/mysqli/mysqli_api.c (#21791, #22227). */
final class mysqli_options extends Internal
{
    public function __construct(string $name = 'mysqli_options')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('mysqli_options() expects at least 3 arguments, '.\count($frame->calledArgs).' given');
        }
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_options', 3);
        $option = MysqliProceduralLink::optionalIntArg($frame, 1);
        $value = MysqliProceduralLink::optionValue($frame->calledArgs[2]);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_options() requires VM context');
        MysqliProceduralLink::setBoolReturn($frame, VmMysqli::optionsOnLink($obj, $ctx, $option, $value));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_options() is not implemented for JIT (issue #21791)');
    }
}
