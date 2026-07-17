<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\spl\SplArrayStorage;

/** PharData — .tar archives (php-src ext/phar/phar_object.c; #6490, #19893). */
final class PharDataBuiltin
{
    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[VmPharData::CLASS_LC])
            && isset($ctx->classes[VmPharData::CLASS_LC]->methods['addemptydir'])) {
            return;
        }

        if (!isset($ctx->classes[VmPhar::CLASS_LC])) {
            BuiltinClasses::registerPhar($ctx);
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = $ctx->classes[VmPharData::CLASS_LC] ?? new ClassEntry('PharData');
        $entry->parentLc = VmPhar::CLASS_LC;
        $entry->isInternal = true;
        foreach (['arrayaccess' => 'ArrayAccess', 'countable' => 'Countable'] as $lc => $name) {
            if (isset($ctx->classes[$lc]) && !\in_array($name, $entry->interfaces, true)) {
                $entry->interfaces[] = $name;
            }
        }

        $entry->constructor = new PharDataConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $map = [
            'addfromstring' => [PharDataAddFromString::class, 'addFromString'],
            'addemptydir' => [PharDataAddEmptyDir::class, 'addEmptyDir'],
            'addfile' => [PharDataAddFile::class, 'addFile'],
            'buildfromdirectory' => [PharDataBuildFromDirectory::class, 'buildFromDirectory'],
            'buildfromiterator' => [PharDataBuildFromIterator::class, 'buildFromIterator'],
            'compress' => [PharDataCompress::class, 'compress'],
            'decompress' => [PharDataDecompress::class, 'decompress'],
            'converttodata' => [PharDataConvertToData::class, 'convertToData'],
            'converttoexecutable' => [PharDataConvertToExecutable::class, 'convertToExecutable'],
            'extractto' => [PharDataExtractTo::class, 'extractTo'],
            'getpath' => [PharDataGetPath::class, 'getPath'],
            'offsetset' => [PharDataOffsetSet::class, 'offsetSet'],
            'offsetget' => [PharDataOffsetGet::class, 'offsetGet'],
            'offsetexists' => [PharDataOffsetExists::class, 'offsetExists'],
            'offsetunset' => [PharDataOffsetUnset::class, 'offsetUnset'],
        ];
        foreach ($map as $lc => [$class, $name]) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
            $entry->methodNames[$lc] = $name;
        }

        $ctx->classes[VmPharData::CLASS_LC] = $entry;
    }
}

final class PharDataConstruct extends VmClassMethod
{
    public function __construct() { parent::__construct('__construct'); }
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
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
    public function __construct() { parent::__construct('addFromString'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf('PharData::addFromString() expects at least 2 arguments, %d given', \max(0, $argc - 1)));
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::addFromString');
        VmPharData::addFromString(
            $object,
            VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::addFromString', 0, 'localname'),
            VmPharData::coercePathArg($frame->calledArgs[2], 'PharData::addFromString', 1, 'contents')
        );
        if (null !== $frame->returnVar) { $frame->returnVar->bool(true); }
    }
}

final class PharDataAddEmptyDir extends VmClassMethod
{
    public function __construct() { parent::__construct('addEmptyDir'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('PharData::addEmptyDir() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::addEmptyDir');
        VmPharData::addEmptyDir($object, VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::addEmptyDir', 0, 'dirname'));
        if (null !== $frame->returnVar) { $frame->returnVar->bool(true); }
    }
}

final class PharDataAddFile extends VmClassMethod
{
    public function __construct() { parent::__construct('addFile'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('PharData::addFile() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::addFile');
        $filename = VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::addFile', 0, 'filename');
        $local = $argc >= 3 ? VmPharData::coercePathArg($frame->calledArgs[2], 'PharData::addFile', 1, 'localname') : null;
        VmPharData::addFile($object, $filename, $local);
        if (null !== $frame->returnVar) { $frame->returnVar->bool(true); }
    }
}

final class PharDataBuildFromDirectory extends VmClassMethod
{
    public function __construct() { parent::__construct('buildFromDirectory'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('PharData::buildFromDirectory() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::buildFromDirectory');
        $dir = VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::buildFromDirectory', 0, 'directory');
        $pattern = $argc >= 3 ? VmPharData::coercePathArg($frame->calledArgs[2], 'PharData::buildFromDirectory', 1, 'pattern') : null;
        $map = VmPharData::buildFromDirectory($object, $dir, $pattern);
        if (null !== $frame->returnVar) { $frame->returnVar->array(VmPharData::mapToHashTable($map)); }
    }
}

final class PharDataBuildFromIterator extends VmClassMethod
{
    public function __construct() { parent::__construct('buildFromIterator'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('PharData::buildFromIterator() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::buildFromIterator');
        $iterVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $iterVar->type) {
            throw new \TypeError('PharData::buildFromIterator(): Argument #1 ($iterator) must be of type Traversable');
        }
        $base = $argc >= 3 ? VmPharData::coercePathArg($frame->calledArgs[2], 'PharData::buildFromIterator', 1, 'baseDirectory') : null;
        $iterObj = $iterVar->toObject();
        $lc = \strtolower($iterObj->class->name);
        $pathMap = [];
        if (\in_array($lc, ['arrayiterator', 'arrayobject'], true)) {
            foreach (SplArrayStorage::getArrayCopy($iterObj)->iterateKeyed(true) as $key => $val) {
                $pathMap[\is_int($key) ? $key : (string) $key] = $val->toString();
            }
        } else {
            throw new \UnexpectedValueException('PharData::buildFromIterator() currently supports ArrayIterator/ArrayObject path maps');
        }
        $map = VmPharData::buildFromPathMap($object, $pathMap, $base);
        if (null !== $frame->returnVar) { $frame->returnVar->array(VmPharData::mapToHashTable($map)); }
    }
}

final class PharDataCompress extends VmClassMethod
{
    public function __construct() { parent::__construct('compress'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('PharData::compress() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::compress');
        $compression = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $ext = $argc >= 3 ? VmPharData::coercePathArg($frame->calledArgs[2], 'PharData::compress', 1, 'extension') : null;
        $out = VmPharData::compress($object, $frame->vmContext, $compression, $ext);
        if (null !== $frame->returnVar) { $frame->returnVar->object($out); }
    }
}

final class PharDataDecompress extends VmClassMethod
{
    public function __construct() { parent::__construct('decompress'); }
    public function execute(Frame $frame): void
    {
        $object = VmPharData::requireReceiver($frame, 'PharData::decompress');
        $ext = \count($frame->calledArgs) >= 2
            ? VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::decompress', 0, 'extension') : null;
        $out = VmPharData::decompress($object, $frame->vmContext, $ext);
        if (null !== $frame->returnVar) { $frame->returnVar->object($out); }
    }
}

final class PharDataConvertToData extends VmClassMethod
{
    public function __construct() { parent::__construct('convertToData'); }
    public function execute(Frame $frame): void
    {
        $object = VmPharData::requireReceiver($frame, 'PharData::convertToData');
        $out = VmPharData::convertToData($object, $frame->vmContext);
        if (null !== $frame->returnVar) { $frame->returnVar->object($out); }
    }
}

final class PharDataConvertToExecutable extends VmClassMethod
{
    public function __construct() { parent::__construct('convertToExecutable'); }
    public function execute(Frame $frame): void
    {
        VmPharData::convertToExecutable(VmPharData::requireReceiver($frame, 'PharData::convertToExecutable'));
    }
}

final class PharDataGetPath extends VmClassMethod
{
    public function __construct() { parent::__construct('getPath'); }
    public function execute(Frame $frame): void
    {
        $object = VmPharData::requireReceiver($frame, 'PharData::getPath');
        if (null !== $frame->returnVar) { $frame->returnVar->string(VmPharData::path($object)); }
    }
}

final class PharDataExtractTo extends VmClassMethod
{
    public function __construct() { parent::__construct('extractTo'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('PharData::extractTo() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::extractTo');
        $ok = VmPharData::extractTo($object, VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::extractTo', 0, 'directory'));
        if (null !== $frame->returnVar) { $frame->returnVar->bool($ok); }
    }
}

final class PharDataOffsetSet extends VmClassMethod
{
    public function __construct() { parent::__construct('offsetSet'); }
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('PharData::offsetSet() expects exactly 2 arguments');
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::offsetSet');
        VmPharData::offsetSet(
            $object,
            VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::offsetSet', 0, 'localname'),
            VmPharData::coercePathArg($frame->calledArgs[2], 'PharData::offsetSet', 1, 'value')
        );
    }
}

final class PharDataOffsetGet extends VmClassMethod
{
    public function __construct() { parent::__construct('offsetGet'); }
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PharData::offsetGet() expects exactly 1 argument');
        }
        if (null === $frame->returnVar) { return; }
        $object = VmPharData::requireReceiver($frame, 'PharData::offsetGet');
        $var = VmPharData::offsetGet($object, VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::offsetGet', 0, 'localname'), $frame->vmContext);
        $frame->returnVar->object($var->toObject());
    }
}

final class PharDataOffsetExists extends VmClassMethod
{
    public function __construct() { parent::__construct('offsetExists'); }
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PharData::offsetExists() expects exactly 1 argument');
        }
        $object = VmPharData::requireReceiver($frame, 'PharData::offsetExists');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPharData::offsetExists($object, VmPharData::coercePathArg($frame->calledArgs[1], 'PharData::offsetExists', 0, 'localname')));
        }
    }
}

final class PharDataOffsetUnset extends VmClassMethod
{
    public function __construct() { parent::__construct('offsetUnset'); }
    public function execute(Frame $frame): void {}
}
