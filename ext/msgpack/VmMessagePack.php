<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * MessagePack / MessagePackUnpacker OO surface (PECL msgpack/msgpack-php msgpack_class.c; #27872).
 *
 * Minimal pack/unpack path delegates to {@see VmMsgpack} — same bytes as msgpack_pack/unpack.
 */
final class VmMessagePack
{
    public const CLASS_LC = 'messagepack';

    public const UNPACKER_LC = 'messagepackunpacker';

    public static function registerClasses(Context $ctx): void
    {
        self::registerMessagePack($ctx);
        self::registerUnpacker($ctx);
    }

    private static function registerMessagePack(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['pack'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('MessagePack');
        $entry->isInternal = true;

        foreach (MsgpackConstants::registeredConstants() as $name => $value) {
            // Class constants OPT_* (msgpack_init_class) — strip MESSAGEPACK_ prefix.
            $short = match ($name) {
                'MESSAGEPACK_OPT_PHPONLY' => 'OPT_PHPONLY',
                'MESSAGEPACK_OPT_ASSOC' => 'OPT_ASSOC',
                'MESSAGEPACK_OPT_FORCE_F32' => 'OPT_FORCE_F32',
                default => $name,
            };
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$short] = $const;
            $entry->constNames[$short] = $short;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        foreach ([
            'pack' => [new MessagePackPack(), 'pack'],
            'unpack' => [new MessagePackUnpack(), 'unpack'],
            'setoption' => [new MessagePackSetOption(), 'setOption'],
        ] as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $pub;
            $entry->methodNames[$lc] = $name;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function registerUnpacker(Context $ctx): void
    {
        if (isset($ctx->classes[self::UNPACKER_LC])) {
            return;
        }

        $entry = new ClassEntry('MessagePackUnpacker');
        $entry->isInternal = true;
        $ctx->classes[self::UNPACKER_LC] = $entry;
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError($label.' must be called on MessagePack');
        }
        $object = $resolved->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError($label.' must be called on MessagePack');
        }

        return $object;
    }
}

abstract class MessagePackClassMethod extends VmClassMethod
{
}

/** MessagePack::pack($value) — same encode as msgpack_pack (#27872). */
final class MessagePackPack extends MessagePackClassMethod
{
    public function __construct()
    {
        parent::__construct('pack');
    }

    public function execute(Frame $frame): void
    {
        VmMessagePack::requireReceiver($frame->calledArgs[0], 'MessagePack::pack()');
        $userArgc = \count($frame->calledArgs) - 1;
        if (1 !== $userArgc) {
            throw new \ArgumentCountError(
                'MessagePack::pack() expects exactly 1 argument, '.$userArgc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $packed = VmMsgpack::pack($frame->calledArgs[1]);
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
        $frame->returnVar->string($packed);
    }
}

/** MessagePack::unpack($str) — same decode as msgpack_unpack (#27872). */
final class MessagePackUnpack extends MessagePackClassMethod
{
    public function __construct()
    {
        parent::__construct('unpack');
    }

    public function execute(Frame $frame): void
    {
        VmMessagePack::requireReceiver($frame->calledArgs[0], 'MessagePack::unpack()');
        $userArgc = \count($frame->calledArgs) - 1;
        if ($userArgc < 1) {
            throw new \ArgumentCountError(
                'MessagePack::unpack() expects at least 1 argument, '.$userArgc.' given'
            );
        }
        if ($userArgc > 2) {
            throw new \ArgumentCountError(
                'MessagePack::unpack() expects at most 2 arguments, '.$userArgc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'MessagePack::unpack',
            1,
            'str'
        );
        $decoded = VmMsgpack::unpack($data, 0, $frame);
        if (false === $decoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }
}

/**
 * MessagePack::setOption($option, $value) — accept OPT_* without changing encode yet (#27872).
 *
 * Options are stored nowhere for the minimal subset; return true for known OPT_* like PECL.
 */
final class MessagePackSetOption extends MessagePackClassMethod
{
    public function __construct()
    {
        parent::__construct('setOption');
    }

    public function execute(Frame $frame): void
    {
        VmMessagePack::requireReceiver($frame->calledArgs[0], 'MessagePack::setOption()');
        $userArgc = \count($frame->calledArgs) - 1;
        if (2 !== $userArgc) {
            throw new \ArgumentCountError(
                'MessagePack::setOption() expects exactly 2 arguments, '.$userArgc.' given'
            );
        }
        $optionVar = $frame->calledArgs[1]->resolveIndirect();
        $option = $optionVar->toInt(null);
        $ok = \in_array($option, [
            MsgpackConstants::MESSAGEPACK_OPT_PHPONLY,
            MsgpackConstants::MESSAGEPACK_OPT_ASSOC,
            MsgpackConstants::MESSAGEPACK_OPT_FORCE_F32,
        ], true);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}
