<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** microtime() — sub-second clock (VM VmDate; JIT/AOT StringMicrotime LLVM, #6110/#5045/#2186). */
final class microtime extends Internal
{
    public function __construct()
    {
        parent::__construct('microtime');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.stub.php — ArgumentCountError (#28691).
        $this->requireAtMostArgCount($frame, 'microtime', 1);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $asFloat = false;
        if (1 === $argc) {
            $asFloat = VmMath::parseBoolBuiltinArgForFrame($frame, 0, 'microtime', 1, 'as_float');
        }
        if ($asFloat) {
            $frame->returnVar->float(VmDate::microtime(true));

            return;
        }
        $frame->returnVar->string(VmDate::microtime(false));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT) — peer #28228 / #28691.
        if (!$this->requireAtMostJitArgCount($context, $args, 'microtime', 1)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $asFloat = $context->constantFromBool(false);
        if (isset($args[0])) {
            $asFloat = JitBoolArg::lowerZParamBool($context, $args[0], 'microtime', 'as_float', 1);
        }

        return JitDate::microtime($context, $asFloat);
    }
}
