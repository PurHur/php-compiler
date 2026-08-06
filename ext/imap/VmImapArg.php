<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;

/** IMAP\Connection argument helpers (php-src ext/imap/php_imap.c Z_PARAM_OBJECT_OF_CLASS; #3663). */
final class VmImapArg
{
    public static function requireConnectionObject(Variable $var, string $functionName, int $argNum = 1): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($imap) must be of type IMAP\\Connection, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($imap) must be of type IMAP\\Connection, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (!VmImapCore::isConnectionObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($imap) must be of type IMAP\\Connection, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmImapCore::isLiveConnectionObject($object)) {
            throw new \TypeError('IMAP\\Connection is already closed');
        }

        return $object;
    }

    /**
     * IMAP\Connection arg that allows a closed stream (php-src imap_is_open; #27674).
     *
     * Unlike {@see requireConnectionObject}, closed connections do not throw — callers
     * decide (imap_is_open returns false).
     */
    public static function requireConnectionObjectAllowClosed(
        Variable $var,
        string $functionName,
        int $argNum = 1
    ): ObjectEntry {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($imap) must be of type IMAP\\Connection, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($imap) must be of type IMAP\\Connection, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (!VmImapCore::isConnectionObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($imap) must be of type IMAP\\Connection, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }

        return $object;
    }
}
