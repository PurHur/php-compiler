<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\SapiOutput;
use PHPLLVM\Value;

/** ini_set() and ini_alter() alias (php-src PHP_FALIAS, issue #6085). */
final class ini_set_ extends Internal
{
    public function __construct(string $name = 'ini_set')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException($fn.'() requires exactly two arguments');
        }
        if (null === $frame->vmContext) {
            return;
        }
        $option = VmString::coerceStringBuiltinArg($frame->calledArgs[0], $fn, 0, 'option');
        $value = VmIniValue::coerceValueArg($frame->calledArgs[1], $fn);
        if (self::rejectSessionIniAfterHeadersSent($frame, $option)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $result = VmIni::set($frame->vmContext, $option, $value);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (2 !== \count($args)) {
            throw new \LogicException($fn.'() requires exactly two arguments');
        }
        $optionStr = JitStringBuiltinArg::lower($context, $args[0], $fn, 0, 'option');
        $valueStr = JitIniValueArg::lower($context, $args[1], $fn);

        return JitIni::set($context, $optionStr, $valueStr);
    }

    /**
     * php-src ext/session/session.c — session ini cannot change after headers sent (#11548).
     */
    private static function rejectSessionIniAfterHeadersSent(Frame $frame, string $option): bool
    {
        if (!SapiOutput::headersSent()) {
            return false;
        }
        $key = strtolower($option);
        if (!in_array($key, ['session.save_path', 'session.gc_maxlifetime'], true)) {
            return false;
        }
        if (null === $frame->vmContext) {
            return true;
        }
        $frame->vmContext->errors->triggerError(
            'Session ini settings cannot be changed after headers have already been sent',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );

        return true;
    }
}
