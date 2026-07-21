<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\ext\standard\VmHttpBuildQuery;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** msg_set_queue() — set System V message queue attributes (php-src ext/sysvmsg/sysvmsg.c; #21633). */
final class msg_set_queue extends Internal
{
    public function __construct()
    {
        parent::__construct('msg_set_queue');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'msg_set_queue() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        MsgArgs::requireAvailable('msg_set_queue');
        $object = MsgArgs::parseQueue($frame, 'msg_set_queue');
        $host = MsgArgs::requireHost($object, 'msg_set_queue');
        $dataVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $dataVar->type) {
            throw new \TypeError(\sprintf(
                'msg_set_queue(): Argument #2 ($data) must be of type array, %s given',
                VmStreamArg::debugTypeName($dataVar)
            ));
        }
        /** @var array<string|int, mixed> $data */
        $data = VmHttpBuildQuery::export($dataVar, $frame);

        $ok = VmMsg::setQueue($host, $data);
        if (!$ok) {
            $this->triggerWarning($frame, 'msg_set_queue() failed');
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'msg_set_queue() is not supported for JIT/AOT in this compiler build (issue #21633)'
        );
    }

    private function triggerWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
