<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal NestedJIT: attach empty stdClass + long info into SOS HT (#33876).
 */
final class phpc_native_sos_attach_empty_stdclass_long extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_native_sos_attach_empty_stdclass_long');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_native_sos_attach_empty_stdclass_long() is JIT-only (#33876)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_native_sos_attach_empty_stdclass_long() expects 2 arguments');
        }
        SosAttachNativeOpsJit::attachEmptyStdClassLong($context, $args[0], $args[1]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
