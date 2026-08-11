<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * socket_addrinfo_lookup() — getaddrinfo(3) as AddressInfo[] (php-src ext/sockets; #6064).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_addrinfo_lookup)
 */
final class socket_addrinfo_lookup extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_addrinfo_lookup');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'socket_addrinfo_lookup() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'socket_addrinfo_lookup() expects at most 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('socket_addrinfo_lookup() requires VM context');
        }

        // Z_PARAM_STR — soft-null outside strict_types; strict → TypeError (#30337).
        // userArgIndex is 0-based for Argument/parameter #N messages (same as socket_connect).
        $host = VmString::stringBuiltinArgForFrame($frame, 0, 'socket_addrinfo_lookup', 0, 'host', false);
        $service = null;
        if ($argc >= 2) {
            $service = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[1],
                'socket_addrinfo_lookup',
                1,
                'service'
            );
        }
        $hints = [];
        if ($argc >= 3) {
            $hintsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $hintsVar->type) {
                throw new \TypeError(\sprintf(
                    'socket_addrinfo_lookup(): Argument #3 ($hints) must be of type array, %s given',
                    \PHPCompiler\ext\standard\VmStreamArg::debugTypeName($hintsVar)
                ));
            }
            $hints = self::parseHints($hintsVar->toArray());
        }

        $list = VmSocketAddrinfo::lookup($host, $service, $hints, $frame->vmContext);
        if (false === $list) {
            BuiltinExecute::writeReturn(
                $frame,
                static fn (Variable $ret) => $ret->bool(false)
            );

            return;
        }

        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($list): void {
                $ht = new HashTable();
                foreach ($list as $i => $object) {
                    $slot = new Variable();
                    $slot->object($object);
                    $ht->addIndex($i, $slot);
                }
                $ret->array($ht);
            }
        );
    }

    /**
     * @return array{ai_flags?: int, ai_family?: int, ai_socktype?: int, ai_protocol?: int}
     */
    private static function parseHints(\PHPCompiler\VM\HashTable $ht): array
    {
        $out = [];
        foreach ($ht->iterateKeyed(true) as [$keyVar, $val]) {
            $key = $keyVar->resolveIndirect();
            $name = Variable::TYPE_INTEGER === $key->type ? (string) $key->toInt() : $key->toString();
            if (!\in_array($name, ['ai_flags', 'ai_family', 'ai_socktype', 'ai_protocol'], true)) {
                continue;
            }
            $out[$name] = VmMath::parseIntBuiltinArg($val, 'socket_addrinfo_lookup', 2, 'hints');
        }

        return $out;
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('socket_addrinfo_lookup() JIT lowering not implemented (#6064)');
    }
}
