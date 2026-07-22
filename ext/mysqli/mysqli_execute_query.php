<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mysqli_execute_query() — prepare+bind+execute+get_result (php-src ext/mysqli/mysqli_api.c; #21895).
 *
 * PHP 8.2+; optional list `$params` for bind-in-execute.
 */
final class mysqli_execute_query extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_execute_query');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_execute_query() expects at least 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $link = MysqliProceduralLink::requireLink($frame, 'mysqli_execute_query', 2);
        $query = $frame->calledArgs[1]->resolveIndirect()->toString();
        $params = null;
        if (\count($frame->calledArgs) >= 3) {
            $params = VmMysqli::paramsListFromVariable(
                $frame->calledArgs[2],
                'mysqli_execute_query',
                2
            );
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_execute_query() requires VM context');
        $result = VmMysqli::executeQueryOnLink($link, $query, $params, $ctx, 'mysqli_execute_query', 3);
        if (null === $frame->returnVar) {
            return;
        }
        if (true === $result) {
            $frame->returnVar->bool(true);
        } elseif (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_execute_query() is not implemented for JIT (issue #21895)');
    }
}
