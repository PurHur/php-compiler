<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_prepare() — procedural wrapper (php-src ext/mysqli/mysqli_api.c; #21788). */
final class mysqli_prepare extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_prepare');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_prepare() expects exactly 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $link = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $link->type) {
            throw new \TypeError('mysqli_prepare(): Argument #1 ($mysql) must be of type mysqli, '.MysqliClassMethod::typeLabelPublic($link).' given');
        }
        $sql = $frame->calledArgs[1]->resolveIndirect()->toString();
        $result = VmMysqliStmt::prepareOnLink($link->toObject(), $sql);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_prepare() is not implemented for JIT (issue #21788)');
    }
}
