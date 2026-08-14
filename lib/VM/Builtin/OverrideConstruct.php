<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** Override::__construct() — VM (#5937, Zend zend_attributes.c). */
final class OverrideConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Override::__construct() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Override::__construct() called without $this');
        }
        $object = $receiver->toObject();
        // php-src: marker attribute ctors take no args (AllowDynamicProperties/ReturnTypeWillChange/SensitiveParameter/Override; #31089)
        $this->requireExactUserArgCount($frame, $object->class->name.'::__construct', 0);
    }
}
