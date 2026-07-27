<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPLLVM\Value;

/**
 * Exception / Error hierarchy __construct — store message (+ code defaults) (#23641).
 *
 * php-src: Zend/zend_exceptions.c — zend_default_exception_new_ex
 * VM SSOT: {@see \PHPCompiler\VM\Builtin\ExceptionConstruct}
 *
 * Returns a null value box like other void JIT ctors ({@see ReflectionClassConstruct}) so
 * FUNCCALL_EXEC_RETURN does not replace the `new` object with a bare `__object__*` in a way
 * that loses Throwable typing for subsequent `throw` (#23641).
 */
final class ExceptionConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('Exception::__construct() requires $this');
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $object = $context->type->object;

        if (isset($args[1]) && Variable::TYPE_NULL !== $args[1]->type) {
            if (Variable::TYPE_STRING !== $args[1]->type) {
                $msgStr = $context->builder->load($context->constantStringFromString(''));
                $msgVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $msgStr);
            } else {
                $msgStr = $context->helper->loadValue($args[1]);
                $msgVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $msgStr);
            }
        } else {
            $msgStr = $context->builder->load($context->constantStringFromString(''));
            $msgVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $msgStr);
        }

        // Store on the receiver's runtime class when known; else Exception/Error layout.
        $decl = 'Exception';
        $receiver = $args[0];
        if (Variable::TYPE_OBJECT === $receiver->type) {
            // Prefer Exception/Error declaring class (getMessage reads Exception::message).
            foreach (['Exception', 'Error'] as $candidate) {
                try {
                    $cid = $object->lookup($candidate);
                } catch (\Throwable) {
                    continue;
                }
                if ($object->hasProperty($cid, ExceptionSupport::PROP_MESSAGE)) {
                    $decl = $candidate;
                    break;
                }
            }
        }
        $object->storeInstanceProperty($obj, $decl, ExceptionSupport::PROP_MESSAGE, $msgVar);

        // Zend zend_exceptions.c — file/line from construct call site (#23641).
        $filePath = $context->jitAotEntryScriptPath;
        if ('' === $filePath) {
            $filePath = 'Unknown';
        }
        $fileStr = $context->builder->load($context->constantStringFromString($filePath));
        $fileVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $fileStr);
        $object->storeInstanceProperty($obj, $decl, ExceptionSupport::PROP_FILE, $fileVar);
        $line = max(0, $context->callSiteLine);
        $lineVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->constantFromInteger($line)
        );
        $object->storeInstanceProperty($obj, $decl, ExceptionSupport::PROP_LINE, $lineVar);

        if (isset($args[2]) && Variable::TYPE_NULL !== $args[2]->type) {
            if (
                Variable::TYPE_NATIVE_LONG === $args[2]->type
                || Variable::TYPE_VALUE === $args[2]->type
            ) {
                $object->storeInstanceProperty($obj, $decl, ExceptionSupport::PROP_CODE, $args[2]);
            }
        }

        $object->markObjectConstructed($obj);

        // Void ctor result — do not overwrite `new` temp (VM #4540 / ReflectionClassConstruct).
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
