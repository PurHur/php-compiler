<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::isInstance() — VM (#6302, ext/reflection/php_reflection.c). */
final class ReflectionClassIsInstance extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isInstance');
    }

    public function execute(Frame $frame): void
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (1 args) (#30888)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::isInstance', 1);
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        $object = $frame->calledArgs[1]->resolveIndirect();
        $matches = false;
        if (Variable::TYPE_OBJECT === $object->type || Variable::TYPE_ENUM_CASE === $object->type) {
            $matches = VmReflection::isInstanceOfObject($ctx, $object, $entry->name);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($matches);
        }
    }
}
