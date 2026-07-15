<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * PharData — nontar stub archives for .tar (php-src ext/phar/phar_object.c; #6490).
 */
final class PharDataBuiltin
{
    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[VmPharData::CLASS_LC])
            && isset($ctx->classes[VmPharData::CLASS_LC]->methods['extractto'])) {
            return;
        }

        if (!isset($ctx->classes[VmPhar::CLASS_LC])) {
            BuiltinClasses::registerPhar($ctx);
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('PharData');
        $entry->parentLc = VmPhar::CLASS_LC;
        $entry->isInternal = true;
        if (isset($ctx->classes['arrayaccess'])
            && !\in_array('ArrayAccess', $entry->interfaces, true)) {
            $entry->interfaces[] = 'ArrayAccess';
        }
        if (isset($ctx->classes['countable'])
            && !\in_array('Countable', $entry->interfaces, true)) {
            $entry->interfaces[] = 'Countable';
        }

        $entry->constructor = new PharDataConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        foreach ([
            'addfromstring' => PharDataAddFromString::class,
            'extractto' => PharDataExtractTo::class,
            'offsetset' => PharDataOffsetSet::class,
            'offsetget' => PharDataOffsetGet::class,
            'offsetexists' => PharDataOffsetExists::class,
            'offsetunset' => PharDataOffsetUnset::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['addfromstring'] = 'addFromString';
        $entry->methodNames['extractto'] = 'extractTo';
        $entry->methodNames['offsetset'] = 'offsetSet';
        $entry->methodNames['offsetget'] = 'offsetGet';
        $entry->methodNames['offsetexists'] = 'offsetExists';
        $entry->methodNames['offsetunset'] = 'offsetUnset';

        $ctx->classes[VmPharData::CLASS_LC] = $entry;
    }
}

final class PharDataConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('PharData::__construct() expects at least 1 argument, 0 given');
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::__construct');
        $path = VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::__construct', 0, 'filename');
        $object->constructed = true;
        VmPharData::open($object, $path);
    }
}

final class PharDataAddFromString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('addFromString');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'PharData::addFromString() expects at least 2 arguments, %d given',
                \max(0, $argc - 1)
            ));
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::addFromString');
        $local = VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::addFromString', 0, 'localname');
        $contents = VmPharData::coercePathArg($frame->calledArgs[2], 'PharData::addFromString', 1, 'contents');
        VmPharData::addFromString($object, $local, $contents);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class PharDataExtractTo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('extractTo');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'PharData::extractTo() expects at least 1 argument, %d given',
                \max(0, $argc - 1)
            ));
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::extractTo');
        $directory = VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::extractTo', 0, 'directory');
        $ok = VmPharData::extractTo($object, $directory);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class PharDataOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError('PharData::offsetSet() expects exactly 2 arguments');
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::offsetSet');
        $local = VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::offsetSet', 0, 'localname');
        $contents = VmPharData::coercePathArg($frame->calledArgs[2], 'PharData::offsetSet', 1, 'value');
        VmPharData::offsetSet($object, $local, $contents);
    }
}

final class PharDataOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('PharData::offsetGet() expects exactly 1 argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::offsetGet');
        $local = VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::offsetGet', 0, 'localname');
        $var = VmPharData::offsetGet($object, $local, $frame->vmContext);
        $frame->returnVar->object($var->toObject());
    }
}

final class PharDataOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('PharData::offsetExists() expects exactly 1 argument');
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::offsetExists');
        $local = VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::offsetExists', 0, 'localname');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPharData::offsetExists($object, $local));
        }
    }
}

final class PharDataOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        // Phase-1 stub: membership delete deferred (tar rewrite).
    }
}

final class PharFileInfoGetContent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getContent');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError('PharFileInfo::getContent() must be called on object');
        }
        $content = VmPharData::fileInfoContent($receiver->toObject());
        $frame->returnVar->string($content);
    }
}
