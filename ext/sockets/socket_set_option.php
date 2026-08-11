<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * socket_set_option() — setsockopt(2) (php-src ext/sockets/sockets.c; #6176).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_set_option)
 */
class socket_set_option extends Internal
{
    private const SO_LINGER = 13;
    private const SO_RCVTIMEO = 20;
    private const SO_SNDTIMEO = 21;

    public function __construct()
    {
        parent::__construct('socket_set_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $fn = $this->getName();
        if (4 !== $argc) {
            throw new \ArgumentCountError(
                $fn.'() expects exactly 4 arguments, '.$argc.' given'
            );
        }

        $object = VmSocketArg::requireSocketObject($frame->calledArgs[0], $fn, 1);
        $level = VmSocketArg::requireIntArg($frame, 1, $fn, 2, 'level');
        $option = VmSocketArg::requireIntArg($frame, 2, $fn, 3, 'option');
        $valueVar = $frame->calledArgs[3]->resolveIndirect();
        if (
            self::SO_RCVTIMEO === $option
            || self::SO_SNDTIMEO === $option
            || self::SO_LINGER === $option
        ) {
            if (Variable::TYPE_ARRAY !== $valueVar->type) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #4 ($value) must be of type array, %s given',
                    $fn,
                    \PHPCompiler\ext\standard\VmStreamArg::debugTypeName($valueVar)
                ));
            }
            $value = self::hashTableToPhpArray($valueVar->toArray());
        } else {
            $value = VmMath::parseIntBuiltinArg($valueVar, $fn, 4, 'value');
        }
        $ok = VmSockets::setOption($object, $level, $option, $value, $frame);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool($ok)
        );
    }

    /**
     * @return array<string|int, mixed>
     */
    private static function hashTableToPhpArray(\PHPCompiler\VM\HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$keyVar, $val]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INT === $key->type) {
                $outKey = $key->toInt();
            } else {
                $outKey = $key->toString();
            }
            if (Variable::TYPE_INT === $val->type) {
                $out[$outKey] = $val->toInt();
            } elseif (Variable::TYPE_STRING === $val->type) {
                $out[$outKey] = $val->toString();
            } elseif (Variable::TYPE_BOOL === $val->type) {
                $out[$outKey] = $val->toBool();
            } elseif (Variable::TYPE_FLOAT === $val->type) {
                $out[$outKey] = $val->toFloat();
            } else {
                $out[$outKey] = null;
            }
        }

        return $out;
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_set_option() JIT lowering not implemented (#6176)');
    }
}
