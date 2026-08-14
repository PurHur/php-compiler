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
        // Static factory — calledArgs are user args only (php-src zim_WeakReference_create, #30867).
        $this->requireExactArgCount($frame, 'WeakReference::create', 1);
        if (null === $frame->vmContext) {
            throw new \LogicException('WeakReference::create() requires VM context');
        }
        $target = WeakRefSupport::requireWeakReferentObject(
            $frame->calledArgs[0],
            'WeakReference::create() argument #1'
        );
        $class = $frame->vmContext->classes['weakreference'] ?? null;
        if (null === $class) {
            throw new \LogicException('WeakReference is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $ref = new Variable(Variable::TYPE_OBJECT);
        $ref->object($entry);
        $targetSlot = WeakRefSupport::targetSlot($entry);
        $targetSlot->weakObject($target);
        WeakRefRegistry::registerWeakRef(
            WeakRefSupport::targetObjectId($frame->calledArgs[0]),
            $targetSlot,
            $entry
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($ref);
        }
    }
}
