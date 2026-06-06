<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
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
}
