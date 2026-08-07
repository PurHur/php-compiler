<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** ini_get() — VM + JIT subset matching ini_set() keys (issue #1374, #1492). */
final class ini_get_ extends Internal
{
    public function __construct()
    {
        parent::__construct('ini_get');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#28690).
        $this->requireExactArgCount($frame, 'ini_get', 1);
        if (null === $frame->vmContext) {
            return;
        }
        $option = IniOptionArg::vmOption($frame, 'ini_get');
        if (null === $frame->returnVar) {
            return;
        }
        $key = \strtolower($option);
        if ('url_rewriter.tags' === $key) {
            $frame->returnVar->string(VmUrlRewriterOb::getTags());

            return;
        }
        if ('url_rewriter.hosts' === $key) {
            $frame->returnVar->string(VmUrlRewriterOb::getHosts());

            return;
        }
        if (\PHPCompiler\ext\soap\SoapWsdlCache::isIniKey($key)) {
            $v = \PHPCompiler\ext\soap\SoapWsdlCache::iniGet($option);
            if (false === $v) {
                $frame->returnVar->bool(false);
            } else {
                $frame->returnVar->string($v);
            }

            return;
        }
        $result = VmIni::get($frame->vmContext, $option);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT/JIT) — #28690.
        if (!$this->requireExactJitArgCount($context, $args, 'ini_get', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        // Compile-time non-string TypeError (int/… on 8.4; null soft-coerces #21312):
        // emit abort without linking IniRuntime (thin AOT lacks full IniJitHelper; #20361).
        if (IniOptionArg::jitOptionRejectsWithoutIniCall($context, $args[0])) {
            IniOptionArg::jitOption($context, $args[0], 'ini_get');

            return $context->getTypeFromString('__value__*')->constNull();
        }
        $optionStr = IniOptionArg::jitOption($context, $args[0], 'ini_get');

        return JitIni::get($context, $optionStr);
    }
}
