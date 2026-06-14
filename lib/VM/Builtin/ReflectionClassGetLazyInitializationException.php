<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getLazyInitializationException() — VM (#6514, ext/reflection/php_reflection.c). */
final class ReflectionClassGetLazyInitializationException extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLazyInitializationException');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::getLazyInitializationException() expects an object');
        }
        ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError(
                'ReflectionClass::getLazyInitializationException(): Argument #1 ($object) must be of type object'
            );
        }
        $object = $objectVar->toObject();
        if (!LazyObjectSupport::isLazyObject($object)) {
            throw new \TypeError(
                'ReflectionClass::getLazyInitializationException(): Argument #1 ($object) must be a lazy object'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(LazyObjectSupport::getLazyInitializationException($object));
    }
}
