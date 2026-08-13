<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** json_last_error() — VM via host JSON; JIT/AOT via __compiler_json_last_error (issue #1173). */
final class json_last_error_ extends Internal
{
    public function __construct()
    {
        parent::__construct('json_last_error');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30591; ext/json/json.c / json.stub.php).
        $this->requireExactArgCount($frame, 'json_last_error', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmJson::lastError());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30591 / peer #30525).
        if (!$this->requireExactJitArgCount($context, $args, 'json_last_error', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitJsonLastError::invoke($context);
    }
}
