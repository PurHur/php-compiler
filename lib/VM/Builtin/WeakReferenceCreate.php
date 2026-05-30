<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakRefRegistry;
use PHPCompiler\VM\WeakRefSupport;

/** WeakReference::create() — VM stub (#1366). */
final class WeakReferenceCreate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('WeakReference::create() expects exactly 1 argument');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('WeakReference::create() requires VM context');
        }
        $object = WeakRefSupport::requireObject($frame->calledArgs[0], 'WeakReference::create() argument #1');
        $class = $frame->vmContext->classes['weakreference'] ?? null;
        if (null === $class) {
            throw new \LogicException('WeakReference is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $ref = new Variable(Variable::TYPE_OBJECT);
        $ref->object($entry);
        WeakRefSupport::targetSlot($entry)->indirect($object);
        WeakRefRegistry::registerWeakRef($object->toObject()->id, WeakRefSupport::targetSlot($entry), $entry);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($ref);
        }
    }
}
