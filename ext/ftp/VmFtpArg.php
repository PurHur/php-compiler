<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;

/** FTP\Connection argument helpers (php-src ext/ftp/php_ftp.c Z_PARAM_OBJECT_OF_CLASS; #3353). */
final class VmFtpArg
{
    public static function requireConnectionObject(Variable $var, string $functionName, int $argNum = 1): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($ftp) must be of type FTP\\Connection, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($ftp) must be of type FTP\\Connection, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (!VmFtpCore::isConnectionObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($ftp) must be of type FTP\\Connection, %s given',
                $functionName,
                $argNum,
                $object->class->name
            ));
        }
        if (!VmFtpCore::isLiveConnectionObject($object)) {
            throw new \TypeError('supplied resource is not a valid FTP Connection resource');
        }

        return $object;
    }
}
