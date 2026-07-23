<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_query() — procedural wrapper (php-src ext/mysqli/mysqli_api.c; #3435). */
final class mysqli_query extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_query');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_query() expects at least 2 arguments, '.\count($frame->calledArgs).' given');
        }
        $link = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $link->type) {
            throw new \TypeError('mysqli_query(): Argument #1 ($mysql) must be of type mysqli, '.self::tl($link).' given');
        }
        $obj = $link->toObject();
        $sql = $frame->calledArgs[1]->resolveIndirect()->toString();
        $resultMode = MysqliConstants::MYSQLI_STORE_RESULT;
        if (\count($frame->calledArgs) >= 3) {
            $resultMode = MysqliProceduralLink::optionalIntArg($frame, 2, MysqliConstants::MYSQLI_STORE_RESULT);
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_query() requires VM context');
        $native = VmMysqli::requireNative($obj, $ctx);
        $result = $native->query($sql, $resultMode);
        if (null === $frame->returnVar) {
            return;
        }
        if (true === $result) {
            $frame->returnVar->bool(true);
        } elseif (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $ctx = VmMysqli::state($obj)->ctx ?? throw new \LogicException('No VM context');
            $frame->returnVar->object(VmMysqliResult::wrap($ctx, $result));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_query() is not implemented for JIT (issue #3435)');
    }

    private static function tl(Variable $v): string
    {
        return match ($v->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            default => 'unknown',
        };
    }
}
