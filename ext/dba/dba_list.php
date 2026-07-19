<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** dba_list() — php-src ext/dba/dba.c (#21167). */
final class dba_list extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_list');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'dba_list() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        $paths = VmDbaConnection::listPaths();
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($paths): void {
                $ht = new HashTable();
                foreach ($paths as $id => $path) {
                    $val = new Variable(Variable::TYPE_STRING);
                    $val->string($path);
                    $ht->add((string) $id, $val);
                }
                $ret->array($ht);
            }
        );
    }
}
