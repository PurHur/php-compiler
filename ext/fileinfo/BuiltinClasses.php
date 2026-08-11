<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context as JitContext;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * Register finfo builtin class (php-src ext/fileinfo/fileinfo.stub.php; #3366).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[VmFinfo::CLASS_LC]) && self::classIsComplete($ctx->classes[VmFinfo::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[VmFinfo::CLASS_LC])
            ? $ctx->classes[VmFinfo::CLASS_LC]
            : new ClassEntry('finfo');

        $entry->constructor = new FinfoConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'file' => FinfoFileMethod::class,
            'buffer' => FinfoBufferMethod::class,
            'set_flags' => FinfoSetFlagsMethod::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['set_flags'] = 'set_flags';
        $entry->isInternal = true;
        $ctx->classes[VmFinfo::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['file'], $entry->methods['buffer'], $entry->methods['set_flags']);
    }
}

final class FinfoConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // Receiver + optional flags + optional magic_database
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'finfo::__construct() expects at most 2 arguments, %d given',
                \max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError('finfo::__construct(): Argument #1 ($object) must be of type object');
        }
        $object = $receiver->toObject();
        $flags = VmFinfo::coerceFlagsArg($frame, 1, 'finfo::__construct', 1, 'flags');
        // magic_database path accepted for signature parity; ignored (PHP sniff).
        if (isset($frame->calledArgs[2])) {
            VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'finfo::__construct', 1, 'magic_database');
        }
        $object->constructed = true;
        VmFinfo::bind($object, $flags);
    }

    public function call(JitContext $context, JITVariable ...$args): Value
    {
        return (new \PHPCompiler\JIT\Call\FinfoConstruct())->call($context, ...$args);
    }
}

final class FinfoFileMethod extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'finfo::file() expects at least 1 argument, %d given',
                \max(0, $argc - 1)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $object = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'finfo::file', 0, 'filename');
        $flags = VmFinfo::coerceFlagsArg($frame, 2, 'finfo::file', 2, 'flags');
        $result = VmFinfo::file($object, $path, $flags, $frame, 'finfo::file');
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(JitContext $context, JITVariable ...$args): Value
    {
        return JitFinfoFile::invokeMethod($context, ...$args);
    }
}

final class FinfoBufferMethod extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('buffer');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'finfo::buffer() expects at least 1 argument, %d given',
                \max(0, $argc - 1)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $object = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $string = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'finfo::buffer', 0, 'string');
        $flags = VmFinfo::coerceFlagsArg($frame, 2, 'finfo::buffer', 2, 'flags');
        $result = VmFinfo::buffer($object, $string, $flags, 'finfo::buffer');
        $frame->returnVar->string($result);
    }

    public function call(JitContext $context, JITVariable ...$args): Value
    {
        return JitFinfoBuffer::invokeMethod($context, ...$args);
    }
}

final class FinfoSetFlagsMethod extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('set_flags');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'finfo::set_flags() expects exactly 1 argument, %d given',
                \max(0, $argc - 1)
            ));
        }
        $object = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $flags = VmFinfo::coerceFlagsArg($frame, 1, 'finfo::set_flags', 1, 'flags');
        $ok = VmFinfo::setFlags($object, $flags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
