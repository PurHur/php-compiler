<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\ext\standard\VmJson;
use PHPLLVM\Value;

final class pcntl_waitpid extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_waitpid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // php-src ext/pcntl/pcntl.stub.php — process_id, &status, flags=0, &resource_usage=[] (#27849)
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'pcntl_waitpid() expects at least 2 arguments and at most 4, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::processAvailable()) {
            throw new \Error('pcntl_waitpid() is not available in this compiler build');
        }
        $pid = InternalStrictArg::requireInt($frame, 0, 'pcntl_waitpid', 'process_id')->toInt();
        $flags = 0;
        if ($argc >= 3) {
            $flags = InternalStrictArg::requireInt($frame, 2, 'pcntl_waitpid', 'flags')->toInt();
        }
        $status = 0;
        $resourceUsage = null;
        $captureRusage = $argc >= 4;
        if ($captureRusage) {
            $resourceUsage = [];
        }
        $waitRc = VmPcntl::waitpid($pid, $status, $flags, $captureRusage, $resourceUsage);
        // ZEND_SEND_REF — write through byRefTarget so caller $status updates (#19564)
        $frame->calledArgs[1]->byRefTarget()->int($status);
        if ($captureRusage) {
            $frame->calledArgs[3]->byRefTarget()->copyFrom(VmJson::import($resourceUsage ?? []));
        }
        $frame->returnVar->int($waitRc);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_waitpid() is not implemented for JIT in this compiler build (issue #3327)');
    }
}
