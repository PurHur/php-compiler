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
 * gethostbyaddr() — reverse DNS for IPv4 (ext/standard/dns.c parity, #5854, #29809).
 *
 * VM: VmDns (/etc/hosts then UDP PTR via resolv.conf). JIT/AOT: GethostbyaddrJitHelper PHP (#9474).
 * Excess/missing argc → Zend ArgumentCountError (#30546).
 * Z_PARAM_STR: strict_types → TypeError on null; soft path DEP+coerce (#29809).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dns.c PHP_FUNCTION(gethostbyaddr)
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php string $ip
 */
final class gethostbyaddr extends Internal
{
    public function __construct()
    {
        parent::__construct('gethostbyaddr');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30546; ext/standard/basic_functions.stub.php).
        $this->requireExactArgCount($frame, 'gethostbyaddr', 1);
        // Z_PARAM_STR — caller strict_types → TypeError on null; else soft-null (#29809).
        // Param name $ip matches php-src basic_functions.stub.php / zend TypeError text.
        $ip = VmString::stringBuiltinArgForFrame($frame, 0, 'gethostbyaddr', 0, 'ip', false);
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
        // Catchable ArgumentCountError under AOT try/catch (#30546).
        if (!$this->requireExactJitArgCount($context, $args, 'gethostbyaddr', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        // Soft-null outside strict_types; strict → TypeError (#29809).
        $ip = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'gethostbyaddr', 0, 'ip')
            : JitStringBuiltinArg::lower($context, $args[0], 'gethostbyaddr', 0, 'ip', 'string', null, false);

        return JitGethostbyaddr::invoke($context, $ip);
    }
}
