<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Register tidy class (php-src ext/tidy/tidy.stub.php; #21464).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[VmTidy::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('tidy');
        $entry->isInternal = true;
        $clean = new TidyCleanRepair();
        $entry->methods['cleanrepair'] = $clean;
        $entry->methodVisibility['cleanrepair'] = $pub;
        $entry->methodNames['cleanrepair'] = 'cleanRepair';
        $ctx->classes[VmTidy::CLASS_LC] = $entry;
    }
}

/** tidy::cleanRepair() — host bridge (#21464). */
final class TidyCleanRepair extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('cleanRepair');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidy::cleanRepair() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::cleanRepair() called without $this');
        }
        $ok = VmTidy::cleanRepair($self->toObject(), $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}
