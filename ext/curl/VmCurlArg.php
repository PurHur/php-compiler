<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmStreamArg;

/** Shared curl handle argument guards (php-src ext/curl/interface.c Z_PARAM_OBJECT_OF_CLASS; #6322). */
final class VmCurlArg
{
    public static function requireShareObject(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($share_handle) must be of type CurlShareHandle, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($share_handle) must be of type CurlShareHandle, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (!VmCurlShare::isShareObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($share_handle) must be of type CurlShareHandle, %s given',
                $functionName,
                $argNum,
                self::objectTypeName($object)
            ));
        }

        return $object;
    }

    /**
     * CURLOPT_SHARE value — CurlShareHandle or CurlSharePersistentHandle (php-src share.c; #20530).
     */
    public static function requireShareableObject(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($value) must be of type CurlShareHandle|CurlSharePersistentHandle, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($value) must be of type CurlShareHandle|CurlSharePersistentHandle, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (!VmCurlShare::isShareableObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($value) must be of type CurlShareHandle|CurlSharePersistentHandle, %s given',
                $functionName,
                $argNum,
                self::objectTypeName($object)
            ));
        }

        return $object;
    }

    public static function requireEasyObject(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($handle) must be of type CurlHandle, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($handle) must be of type CurlHandle, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (!VmCurlEasy::isEasyObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($handle) must be of type CurlHandle, %s given',
                $functionName,
                $argNum,
                self::objectTypeName($object)
            ));
        }

        return $object;
    }

    public static function requireMultiObject(Variable $var, string $functionName, int $argNum): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($multi_handle) must be of type CurlMultiHandle, %s given',
                $functionName,
                $argNum,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($multi_handle) must be of type CurlMultiHandle, %s given',
                $functionName,
                $argNum,
                VmStreamArg::debugTypeName($var)
            ));
        }
        $object = $var->toObject();
        if (!VmCurlMulti::isMultiObject($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($multi_handle) must be of type CurlMultiHandle, %s given',
                $functionName,
                $argNum,
                self::objectTypeName($object)
            ));
        }

        return $object;
    }

    private static function objectTypeName(ObjectEntry $object): string
    {
        // Zend TypeError for object-of-class params uses the class name (php-src zend_types.h).
        return $object->class->name;
    }
}
