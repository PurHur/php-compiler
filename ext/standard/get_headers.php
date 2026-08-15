<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * get_headers() — HTTP HEAD via VmHttpFetchPure / VmStreamSocketNative, no host get_headers() (#3309, #8939).
 *
 * php-src: ext/standard/head.c — PHP_FUNCTION(get_headers)
 */
final class get_headers extends Internal
{
    /** php-src head.c — non-http(s) URL warning (#26383). */
    public const NON_HTTP_URL_WARNING = 'get_headers(): This function may only be used against URLs';

    public function __construct()
    {
        parent::__construct('get_headers');
    }

    public function execute(Frame $frame): void
    {
        // php-src head.c / basic_functions.stub.php — split under/over arity (#31192; peer #30677).
        $this->requireArgCountRange($frame, 'get_headers', 1, 3);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }

        $url = VmStreamPath::coerceNonEmptyPathArgForFrame($frame, 0, 'get_headers', 'url');
        $associative = false;
        if ($argc >= 2) {
            $associative = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1],
                'get_headers',
                2,
                'associative'
            );
        }
        // Optional $context accepted for Zend stub arity (#23598); fetch path ignores it for now.
        if ($argc >= 3) {
            // Validate presence only — resource|null; unused until stream-context fetch lands.
            $frame->calledArgs[2]->resolveIndirect();
        }

        if (!VmHttpLastResponseHeaders::isHttpUrl($url)) {
            $frame->vmContext->errors->triggerError(
                self::NON_HTTP_URL_WARNING,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
            $frame->returnVar->bool(false);

            return;
        }

        $headers = VmHttpFetchNative::fetchHeaders($url);
        if (false === $headers) {
            // php-src head.c — php_stream_open_wrapper failure → E_WARNING + false (#26705).
            VmStreamOpenFailure::warnFailedToOpen($frame, 'get_headers', $url);
            $frame->returnVar->bool(false);

            return;
        }

        $formatted = VmHttpHeaders::format($headers, $associative);
        $frame->returnVar->array(VmHttpHeaders::toHashTable($formatted, $associative));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ACE + Zend under/over wording (#31192; peer #30677 / #27763).
        if (!$this->requireArgCountRangeJit($context, $args, 'get_headers', 1, 3)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $argc = \count($args);

        $url = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'get_headers', 0, 'url');
        $associative = $context->constantFromBool(false);
        if ($argc >= 2) {
            JitInternalStrictArg::requireBool($context, $args[1], 'get_headers', 'associative', 2);
            $associative = JitBoolArg::lower(
                $context,
                $args[1],
                'get_headers(): Argument #2 ($associative)'
            );
        }

        return JitGetHeaders::invoke($context, $url, $associative);
    }
}
