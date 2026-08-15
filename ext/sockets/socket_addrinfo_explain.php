<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPLLVM\Value;

/**
 * socket_addrinfo_explain() — AddressInfo fields as array (php-src ext/sockets; #6064).
 *
 * @see https://github.com/php/php-src/blob/master/ext/sockets/sockets.c PHP_FUNCTION(socket_addrinfo_explain)
 */
final class socket_addrinfo_explain extends Internal
{
    public function __construct()
    {
        parent::__construct('socket_addrinfo_explain');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'socket_addrinfo_explain() expects exactly 1 argument, '.$argc.' given'
            );
        }

        $object = self::requireAddressInfo($frame->calledArgs[0], 'socket_addrinfo_explain');
        $info = VmSocketAddrinfo::explain($object);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($info): void {
                $ht = new HashTable();
                foreach (['ai_flags', 'ai_family', 'ai_socktype', 'ai_protocol'] as $k) {
                    $slot = new Variable();
                    $slot->int((int) $info[$k]);
                    $ht->add($k, $slot);
                }
                $addrHt = new HashTable();
                foreach ($info['ai_addr'] as $ak => $av) {
                    $slot = new Variable();
                    if (\is_int($av)) {
                        $slot->int($av);
                    } else {
                        $slot->string((string) $av);
                    }
                    $addrHt->add((string) $ak, $slot);
                }
                $addrSlot = new Variable();
                $addrSlot->array($addrHt);
                $ht->add('ai_addr', $addrSlot);
                $ret->array($ht);
            }
        );
    }

    public static function requireAddressInfo(Variable $var, string $functionName, int $argNum = 1): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($address) must be of type AddressInfo, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($address) must be of type AddressInfo, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (!VmAddressInfo::isAddressInfoObject($object) || null === VmAddressInfo::snapshotFor($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($address) must be of type AddressInfo, %s given',
                $functionName,
                $argNum,
                'object('.$object->class->name.')'
            ));
        }

        return $object;
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSocketAddrinfoExplain::invoke($context, ...$args);
    }
}
