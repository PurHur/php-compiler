<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** dba_key_split() — php-src ext/dba/dba.c (#21168). */
final class dba_key_split extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_key_split');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'dba_key_split() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        $phpKey = self::toPhpKey($var);
        $parts = VmDbaCore::keySplit($phpKey);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($parts): void {
                if (false === $parts) {
                    $ret->bool(false);

                    return;
                }
                $ht = new HashTable();
                foreach ($parts as $part) {
                    $v = new Variable(Variable::TYPE_STRING);
                    $v->string($part);
                    $ht->append($v);
                }
                $ret->array($ht);
            }
        );
    }

    private static function toPhpKey(Variable $var): mixed
    {
        return match ($var->type) {
            Variable::TYPE_NULL => null,
            Variable::TYPE_BOOLEAN => $var->toBool(),
            Variable::TYPE_STRING => $var->toString(),
            Variable::TYPE_INTEGER => (string) $var->toInt(),
            Variable::TYPE_FLOAT => (string) $var->toFloat(),
            default => throw new \TypeError(
                'dba_key_split(): Argument #1 ($key) must be of type string|false|null, '
                .(EnumCaseSupport::isEnumCaseVariable($var)
                    ? EnumCaseSupport::typeNameForVariable($var)
                    : match ($var->type) {
                        Variable::TYPE_ARRAY => 'array',
                        Variable::TYPE_OBJECT => $var->toObject()->class->name,
                        default => 'mixed',
                    }).' given'
            ),
        };
    }
}
