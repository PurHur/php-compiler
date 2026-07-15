<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;

/** Shared socket argument helpers (php-src ext/sockets/sockets.c Z_PARAM_OBJECT_OF_CLASS; #6544). */
final class VmSocketArg
{
    public static function requireSocketObject(Variable $var, string $functionName, int $argNum = 1): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($socket) must be of type Socket, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($socket) must be of type Socket, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (!VmSocket::isSocketObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($socket) must be of type Socket, %s given',
                $functionName,
                $argNum,
                self::objectTypeName($object)
            ));
        }
        if (!VmSocket::isValidSocketObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): supplied resource is not a valid Socket resource',
                $functionName
            ));
        }

        return $object;
    }

    public static function requireHostSocket(Variable $var, string $functionName, int $argNum = 1): \Socket
    {
        $object = self::requireSocketObject($var, $functionName, $argNum);
        $host = VmSocket::hostSocket($object);
        if (null === $host) {
            throw new \TypeError(\sprintf(
                '%s(): supplied resource is not a valid Socket resource',
                $functionName
            ));
        }

        return $host;
    }

    private static function objectTypeName(ObjectEntry $object): string
    {
        return 'object('.$object->class->name.')';
    }

    public static function requireIntArg(Variable $var, string $functionName, int $argNum, string $paramName): int
    {
        return \PHPCompiler\ext\standard\VmMath::parseIntBuiltinArg(
            $var,
            $functionName,
            $argNum,
            $paramName
        );
    }
}
