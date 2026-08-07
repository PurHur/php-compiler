<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
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
        // php-src ext/standard/basic_functions.c — ArgumentCountError (#28690).
        $this->requireExactArgCount($frame, $fn, 2);
        if (null === $frame->vmContext) {
            return;
        }
        $option = IniOptionArg::vmOption($frame, $fn);
        $value = VmIniValue::coerceValueArg($frame->calledArgs[1], $fn);
        if (self::rejectSessionIniAfterHeadersSent($frame, $option)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $key = \strtolower($option);
        // php-src url_rewriter.* PHP_INI_ALL — store on VmUrlRewriterOb (avoid VmIni→NestedJIT coupling, #24370).
        if ('url_rewriter.tags' === $key || 'url_rewriter.hosts' === $key) {
            $old = 'url_rewriter.tags' === $key
                ? VmUrlRewriterOb::getTags()
                : VmUrlRewriterOb::getHosts();
            if ('url_rewriter.tags' === $key) {
                VmUrlRewriterOb::setTags($value);
            } else {
                VmUrlRewriterOb::setHosts($value);
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->string($old);
            }

            return;
        }
        // php-src soap.wsdl_cache_* PHP_INI_ALL (#26511 / peer url_rewriter).
        if (\PHPCompiler\ext\soap\SoapWsdlCache::isIniKey($key)) {
            $old = \PHPCompiler\ext\soap\SoapWsdlCache::iniSet($option, $value);
            if (null !== $frame->returnVar) {
                if (false === $old) {
                    $frame->returnVar->bool(false);
                } else {
                    $frame->returnVar->string($old);
                }
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
        // Catchable ArgumentCountError (AOT/JIT) — #28690.
        if (!$this->requireExactJitArgCount($context, $args, $fn, 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        // Compile-time non-string TypeError (int/… on 8.4; null soft-coerces #21312; #20361).
        if (IniOptionArg::jitOptionRejectsWithoutIniCall($context, $args[0])) {
            IniOptionArg::jitOption($context, $args[0], $fn);

            return $context->getTypeFromString('__value__*')->constNull();
        }
        $optionStr = IniOptionArg::jitOption($context, $args[0], $fn);
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
        if (!in_array($key, ['session.save_path', 'session.gc_maxlifetime', 'session.use_strict_mode'], true)) {
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
