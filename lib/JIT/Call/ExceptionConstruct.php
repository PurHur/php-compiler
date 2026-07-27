<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPLLVM\Value;

/**
 * Exception / Error / *Exception::__construct — JIT/AOT (#23641).
 *
 * php-src: Zend/zend_exceptions.c — zim_exception___construct
 *
 * Engine Throwable classes are external to the user bundle; without this proxy
 * TYPE_NEW skips init (hasConstructor was false) and getMessage() reads an
 * out-of-bounds property slot on a zero-prop object (rc=134 after catch).
 */
final class ExceptionConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Exception::__construct() called without $this');
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $object = $context->type->object;

        // Slot layout matches ExceptionGetMessage (class "Exception", message @ 0).
        $storeClass = 'Exception';

        if (isset($args[1]) && Variable::TYPE_NULL !== $args[1]->type) {
            $msgVar = JitNativeString::coerce($context, $args[1]);
            $object->storeInstanceProperty($obj, $storeClass, ExceptionSupport::PROP_MESSAGE, $msgVar);
        } else {
            $empty = new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString(''))
            );
            $object->storeInstanceProperty($obj, $storeClass, ExceptionSupport::PROP_MESSAGE, $empty);
        }

        if (isset($args[2]) && Variable::TYPE_NULL !== $args[2]->type
            && Variable::TYPE_NATIVE_LONG === $args[2]->type
        ) {
            $object->storeInstanceProperty($obj, $storeClass, ExceptionSupport::PROP_CODE, $args[2]);
        } else {
            $zero = new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->getTypeFromString('int64')->constInt(0, false)
            );
            $object->storeInstanceProperty($obj, $storeClass, ExceptionSupport::PROP_CODE, $zero);
        }

        $emptyFile = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString(''))
        );
        $object->storeInstanceProperty($obj, $storeClass, ExceptionSupport::PROP_FILE, $emptyFile);
        $zeroLine = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $object->storeInstanceProperty($obj, $storeClass, ExceptionSupport::PROP_LINE, $zeroLine);

        $object->markObjectConstructed($obj);

        // Return the receiver — `throw new LogicException(...)` / `$e = new ...` may bind the
        // FUNCCALL_EXEC_RETURN operand (not only the TYPE_NEW temp). A null value-box return
        // left that operand empty and catch/getMessage aborted (#23641).
        return $obj;
    }
}
