<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** dba_handlers() — php-src ext/dba/dba.c (#4422). */
final class dba_handlers extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_handlers');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'dba_handlers() expects at most 1 argument, %d given',
                $argc
            ));
        }
        $fullInfo = false;
        if (1 === $argc) {
            $v = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $v->type) {
                $fullInfo = $v->toBool();
            } elseif (Variable::TYPE_INTEGER === $v->type) {
                $fullInfo = 0 !== $v->toInt();
            } elseif (Variable::TYPE_NULL !== $v->type) {
                $fullInfo = true;
            }
        }
        $handlers = VmDbaCore::handlers();
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($handlers, $fullInfo): void {
                $ht = new HashTable();
                foreach ($handlers as $name) {
                    $val = new Variable(Variable::TYPE_STRING);
                    if ($fullInfo) {
                        $val->string($name.' handler');
                    } else {
                        $val->string($name);
                    }
                    $ht->append($val);
                }
                $ret->array($ht);
            }
        );
    }
}
