<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::getConstructor() — VM (#6302, ext/reflection/php_reflection.c). */
final class ReflectionClassGetConstructor extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getConstructor');
    }

    public function execute(Frame $frame): void
    {
        [, $entry, $ctx] = ReflectionSupport::requireReflectedClassEntry($frame, $frame->calledArgs[0]);
        if (null === $frame->returnVar) {
            return;
        }
        if (!isset($entry->methods['__construct'])) {
            $frame->returnVar->null();

            return;
        }
        $out = new Variable(Variable::TYPE_OBJECT);
        $out->object(ReflectionSupport::newReflectionMethodObject($ctx, $entry, '__construct'));
        $frame->returnVar->copyFrom($out);
    }
}
