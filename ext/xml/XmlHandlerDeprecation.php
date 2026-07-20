<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\CallableCheck;
use PHPCompiler\VM\Variable;

/**
 * PHP 8.4 xml_set_object / non-callable string handler deprecations (php-src ext/xml/xml.c + xml.stub.php; #21522).
 */
final class XmlHandlerDeprecation
{
    private const OBJECT_MESSAGE = 'provide a proper method callable to xml_set_*_handler() functions';

    private const NON_CALLABLE_STRING = 'Passing non-callable strings is deprecated since 8.4';

    public static function emitXmlSetObject(?Frame $frame): void
    {
        if (!CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
            return;
        }
        $meta = new DeprecatedMetadata(self::OBJECT_MESSAGE, '8.4');
        self::emitInternal($frame, $meta->formatFunction('xml_set_object'));
    }

    public static function emitNonCallableString(?Frame $frame, string $function): void
    {
        if (!CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
            return;
        }
        self::emitInternal($frame, $function.'(): '.self::NON_CALLABLE_STRING);
    }

    /**
     * Zend {@code OF!} vs {@code OS}: string/scalar method names that are not global callables.
     */
    public static function isNonCallableStringHandler(Variable $arg, ?Frame $frame): bool
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return false;
        }
        if (Variable::TYPE_OBJECT === $arg->type || Variable::TYPE_ARRAY === $arg->type) {
            return false;
        }
        // Strings and scalar coercion targets — same as XmlSetHandlerFunction::resolveHandler.
        if (Variable::TYPE_STRING !== $arg->type) {
            $coerced = new Variable();
            $coerced->string($arg->toString());
            $arg = $coerced;
        }
        $vm = VM::running();
        if (null === $vm) {
            return true;
        }

        return !CallableCheck::isCallable($arg, $vm->context, $frame);
    }

    private static function emitInternal(?Frame $frame, string $message): void
    {
        $vm = VM::running();
        if (null === $vm) {
            return;
        }
        if (null === $frame) {
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $vm->context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
        }
        $vm->context->errors->internalDeprecated($message, $vm->context, $frame);
    }
}
