<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\SapiOutput;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** session_start() — resume or create file-backed $_SESSION (issues #64, #1182–#1186, #18457). */
class session_start extends Internal
{
    public const NOTICE_ALREADY_ACTIVE = 'session_start(): Ignoring session_start() because a session is already active';

    public function __construct()
    {
        parent::__construct('session_start');
    }

    public function execute(Frame $frame): void
    {
        $readAndClose = false;
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'session_start() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (1 === $argc) {
            $optionsVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $optionsVar->type) {
                $given = Variable::TYPE_OBJECT === $optionsVar->type
                    ? $optionsVar->toObject()->class->name
                    : match ($optionsVar->type) {
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_INTEGER => 'int',
                        Variable::TYPE_FLOAT => 'float',
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_NULL => 'null',
                        default => 'mixed',
                    };
                throw new \TypeError(
                    'session_start(): Argument #1 ($options) must be of type array, '.$given.' given'
                );
            }
            $readAndClose = SessionStartOptions::apply($frame, $optionsVar->toArray());
        }

        if (VmSession::isActive()) {
            $ctx = VmReflection::requireContext($frame);
            $ctx->errors->triggerError(
                self::NOTICE_ALREADY_ACTIVE,
                ErrorReporter::E_NOTICE,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $ctx,
                $frame,
                $frame->callSiteLine
            );
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        if (SapiOutput::headersSent()) {
            $ctx = VmReflection::requireContext($frame);
            $ctx->errors->triggerError(
                VmSession::HEADERS_SENT_START_WARNING,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $ctx,
                $frame,
                $frame->callSiteLine
            );
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $result = VmSession::start($ctx);
        if ($readAndClose && $result) {
            VmSession::readClose();
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                'session_start() expects at most 1 argument, '.\count($args).' given'
            );
        }
        if (1 === \count($args)) {
            return JitSessionStartOptions::invoke($context, $args[0]);
        }

        return JitSessionStart::invoke($context);
    }
}
