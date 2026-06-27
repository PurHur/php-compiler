<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * gethostbyaddr() — reverse DNS for IPv4 (ext/standard/dns.c parity, #5854).
 *
 * VM: VmDns (libc FFI getnameinfo + /etc/hosts). JIT/AOT: GethostbyaddrJitHelper PHP (#9474).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(gethostbyaddr)
 */
final class gethostbyaddr extends Internal
{
    public function __construct()
    {
        parent::__construct('gethostbyaddr');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('gethostbyaddr() requires exactly one argument in this compiler build');
        }
        $ip = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'gethostbyaddr', 0, 'ip_address');
        if (null === $frame->returnVar) {
            return;
        }
        $error = VmDns::ERR_NONE;
        $result = VmDns::gethostbyaddr($ip, $error);
        if (false !== $result) {
            $frame->returnVar->string($result);

            return;
        }
        if (null !== $frame->vmContext) {
            if (VmDns::ERR_INVALID_ADDRESS === $error) {
                $frame->vmContext->errors->triggerError(
                    'Address is not a valid IPv4 or IPv6 address',
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
        }
        if (VmDns::ERR_NOT_FOUND === $error) {
            $frame->returnVar->string($ip);

            return;
        }
        $frame->returnVar->bool(false);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('gethostbyaddr() requires exactly one argument in this compiler build');
        }

        return JitGethostbyaddr::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'gethostbyaddr', 0, 'ip_address')
        );
    }
}
