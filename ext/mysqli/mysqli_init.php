<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mysqli_init() — return an unconnected mysqli object (php-src ext/mysqli/mysqli_api.c; #21803).
 *
 * Equivalent to `new mysqli()` with no arguments. The returned object can later
 * be used with `mysqli_real_connect()`.
 */
final class mysqli_init extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_init');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'mysqli_init', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_init() requires VM context');
        $class = $ctx->classes[VmMysqli::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('mysqli class not registered');
        }
        $entry = new ObjectEntry($class);
        $state = new MysqliState();
        $state->ctx = $ctx;
        if (MysqliExtensionPolicy::hasNativeDriver()) {
            $state->native = new \mysqli();
        }
        VmMysqli::attachState($entry, $state);
        $frame->returnVar->object($entry);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_init() is not implemented for JIT in this compiler build (issue #21803)');
    }
}
