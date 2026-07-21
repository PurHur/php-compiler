<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_fetch_array() — procedural wrapper (php-src ext/mysqli/mysqli_api.c; #3435). */
final class mysqli_fetch_array extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_fetch_array');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('mysqli_fetch_array() expects at least 1 argument, 0 given');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('mysqli_fetch_array(): Argument #1 ($result) must be of type mysqli_result');
        }
        $native = VmMysqliResult::requireNative($var->toObject());
        $mode = MysqliConstants::MYSQLI_BOTH;
        if (\count($frame->calledArgs) >= 2) {
            $modeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $modeVar->type) {
                $mode = $modeVar->toInt();
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $row = match ($mode) {
            MysqliConstants::MYSQLI_ASSOC => $native->fetch_assoc(),
            MysqliConstants::MYSQLI_NUM => $native->fetch_row(),
            default => $native->fetch_array(\MYSQLI_BOTH),
        };
        if (null === $row) {
            $frame->returnVar->null();
        } else {
            VmMysqli::assignRow($frame->returnVar, $row);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_fetch_array() is not implemented for JIT (issue #3435)');
    }
}
