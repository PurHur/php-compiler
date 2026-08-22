<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal NestedJIT: attach empty stdClass + null info into SOS HT (#33876).
 */
final class phpc_native_sos_attach_empty_stdclass_null extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_native_sos_attach_empty_stdclass_null');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_native_sos_attach_empty_stdclass_null() is JIT-only (#33876)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_native_sos_attach_empty_stdclass_null() expects 1 argument');
        }
        SosAttachNativeOpsJit::attachEmptyStdClassNull($context, $args[0]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
