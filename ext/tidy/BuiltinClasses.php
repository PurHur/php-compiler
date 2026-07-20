<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Register tidy class (php-src ext/tidy/tidy.stub.php; #21464, #21498, #21499).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[VmTidy::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $entry = new ClassEntry('tidy');
        $entry->isInternal = true;

        $nullDefault = new Variable(Variable::TYPE_NULL);
        $strProto = new Variable(Variable::TYPE_STRING);
        $entry->properties[] = new ClassProperty(
            'value',
            $nullDefault,
            $strProto,
            false,
            $pub,
            VmTidy::CLASS_LC
        );

        $clean = new TidyCleanRepair();
        $entry->methods['cleanrepair'] = $clean;
        $entry->methodVisibility['cleanrepair'] = $pub;
        $entry->methodNames['cleanrepair'] = 'cleanRepair';

        $repairString = new TidyRepairString();
        $entry->methods['repairstring'] = $repairString;
        $entry->methodVisibility['repairstring'] = $pubStatic;
        $entry->methodNames['repairstring'] = 'repairString';

        $repairFile = new TidyRepairFile();
        $entry->methods['repairfile'] = $repairFile;
        $entry->methodVisibility['repairfile'] = $pubStatic;
        $entry->methodNames['repairfile'] = 'repairFile';

        $ctx->classes[VmTidy::CLASS_LC] = $entry;
    }

    /** Static call may omit receiver; instance-style leaves tidy $this in args[0]. */
    public static function staticArgOffset(Frame $frame): int
    {
        if (\count($frame->calledArgs) < 1) {
            return 0;
        }
        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $first->type
            && VmTidy::CLASS_LC === strtolower($first->toObject()->class->name)) {
            return 1;
        }

        return 0;
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

/** tidy::repairString() — static host bridge (#21498). */
final class TidyRepairString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('repairString');
    }

    public function execute(Frame $frame): void
    {
        $offset = BuiltinClasses::staticArgOffset($frame);
        if (\count($frame->calledArgs) < $offset + 1) {
            throw new \ArgumentCountError('tidy::repairString() expects at least 1 argument, 0 given');
        }
        if (\count($frame->calledArgs) > $offset + 3) {
            throw new \ArgumentCountError(
                'tidy::repairString() expects at most 3 arguments, '.(\count($frame->calledArgs) - $offset).' given'
            );
        }
        $html = VmTidy::htmlStringArg($frame->calledArgs[$offset], 'tidy::repairString', 0);
        $repaired = VmTidy::repairString($html, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($repaired): void {
            if (false === $repaired) {
                $ret->bool(false);

                return;
            }
            $ret->string($repaired);
        });
    }
}

/** tidy::repairFile() — static host bridge (#21498). */
final class TidyRepairFile extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('repairFile');
    }

    public function execute(Frame $frame): void
    {
        $offset = BuiltinClasses::staticArgOffset($frame);
        if (\count($frame->calledArgs) < $offset + 1) {
            throw new \ArgumentCountError('tidy::repairFile() expects at least 1 argument, 0 given');
        }
        if (\count($frame->calledArgs) > $offset + 4) {
            throw new \ArgumentCountError(
                'tidy::repairFile() expects at most 4 arguments, '.(\count($frame->calledArgs) - $offset).' given'
            );
        }
        $filename = VmTidy::htmlStringArg($frame->calledArgs[$offset], 'tidy::repairFile', 0);
        $repaired = VmTidy::repairFile($filename, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($repaired): void {
            if (false === $repaired) {
                $ret->bool(false);

                return;
            }
            $ret->string($repaired);
        });
    }
}
