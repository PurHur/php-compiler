<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * error_log() — write message to SAPI/error log (php-src ext/standard/basic_functions.c; #3380).
 *
 * Z_PARAM_STR $message — null TypeError under caller strict_types only; PROFILE=8.4 soft-null DEP+coerce
 * (#24965 / re-#24178, reverts #23858 over-strict; php-src basic_functions.stub.php).
 * Optional $destination / $additional_headers stay nullable (?string).
 */
final class error_log extends Internal
{
    public function __construct()
    {
        parent::__construct('error_log');
    }

    public function execute(Frame $frame): void
    {
        // php-src basic_functions.c — split under/over arity (#31193; peer #31192 / #30677).
        $this->requireArgCountRange($frame, 'error_log', 1, 4);
        if (null === $frame->returnVar) {
            return;
        }

        $message = VmString::trimFamilyStringArgForFrame($frame, 0, 'error_log', 0, 'message');
        $messageType = 0;
        if (\array_key_exists(1, $frame->calledArgs) && null !== $frame->calledArgs[1]) {
            $messageType = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'error_log', 1, 'message_type');
        }
        $destination = null;
        // Named args may skip optional $destination while filling $additional_headers (#23341).
        if (\array_key_exists(2, $frame->calledArgs) && null !== $frame->calledArgs[2]) {
            $destination = self::coerceOptionalPathArg($frame->calledArgs[2], 'error_log', 2, 'destination');
        }
        if (\array_key_exists(3, $frame->calledArgs) && null !== $frame->calledArgs[3]) {
            $headersArg = $frame->calledArgs[3]->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($headersArg)) {
                throw new \TypeError(\sprintf(
                    'error_log(): Argument #4 ($additional_headers) must be of type ?string, %s given',
                    EnumCaseSupport::typeNameForVariable($headersArg)
                ));
            }
            if (Variable::TYPE_NULL !== $headersArg->type) {
                VmString::coerceZparamStrBuiltinArg($headersArg, 'error_log', 3, 'additional_headers');
            }
        }

        $ok = VmErrorLog::errorLog($messageType, $message, $destination, $frame);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ACE + Zend under/over wording (#31193; peer #31192 / #27763).
        if (!$this->requireArgCountRangeJit($context, $args, 'error_log', 1, 4)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $argc = \count($args);

        $messageType = $argc >= 2 ? $args[1] : null;
        $destination = $argc >= 3 ? $args[2] : null;

        return JitErrorLog::errorLog($context, $args[0], $messageType, $destination);
    }

    private static function coerceOptionalPathArg(Variable $var, string $function, int $argIndex, string $paramName): ?string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type ?string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $function, $argIndex, $paramName);
    }
}
