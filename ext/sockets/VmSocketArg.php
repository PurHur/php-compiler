<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
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

    /**
     * Optional Socket — null means process-level errno (php-src O! / ?Socket; #30267).
     */
    public static function requireSocketObjectOrNull(Variable $var, string $functionName, int $argNum = 1): ?ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        // Reject with ?Socket in the expected type (Zend zend_verify_arg_type for nullable).
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($socket) must be of type ?Socket, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($socket) must be of type ?Socket, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (!VmSocket::isSocketObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($socket) must be of type ?Socket, %s given',
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

    /**
     * Z_PARAM_LONG socket ints — caller strict_types → TypeError on null; soft path DEP+coerce
     * (php-src ext/sockets/sockets.c; #30264 / #30265 / #30266).
     *
     * @param int $argIndex 0-based slot in $frame->calledArgs
     * @param int $argNum 1-based Argument #N in TypeError text
     */
    public static function requireIntArg(
        Frame $frame,
        int $argIndex,
        string $functionName,
        int $argNum,
        string $paramName
    ): int {
        return VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            $argIndex,
            $functionName,
            $argNum,
            $paramName
        );
    }
}
