<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

final class pcntl_waitid extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_waitid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(
                'pcntl_waitid() expects at least 3 arguments and at most 5, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmPcntl::processAvailable()) {
            throw new \Error('pcntl_waitid() is not available in this compiler build');
        }
        $idtype = VmPcntlArg::coerceIntArg($frame->calledArgs[0], 'pcntl_waitid', 0, 'idtype');
        $idArg = $frame->calledArgs[1]->resolveIndirect();
        $id = Variable::TYPE_NULL === $idArg->type ? 0 : VmPcntlArg::coerceIntArg($frame->calledArgs[1], 'pcntl_waitid', 1, 'id');
        $infoOut = $frame->calledArgs[2];
        $options = PcntlConstants::WEXITED;
        if ($argc >= 4) {
            $options = VmPcntlArg::coerceIntArg($frame->calledArgs[3], 'pcntl_waitid', 3, 'flags');
        }
        $frame->returnVar->bool(VmPcntl::waitid($idtype, $id, $infoOut, $options));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_waitid() is not implemented for JIT in this compiler build (issue #6545)');
    }
}
