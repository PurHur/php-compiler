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
use PHPCompiler\ext\standard\VmJson;

/** Phar instance methods — php-src ext/phar/phar_object.c (#20628, #21228, #21229). */
final class PharBuiltin
{
    public static function registerInstanceMethods(Context $ctx): void
    {
        if (!isset($ctx->classes[VmPhar::CLASS_LC])) {
            BuiltinClasses::registerPhar($ctx);
        }
        $entry = $ctx->classes[VmPhar::CLASS_LC];

        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;

        if (!isset($entry->methods['addfromstring'])) {
            foreach (['arrayaccess' => 'ArrayAccess', 'countable' => 'Countable'] as $lc => $name) {
                if (isset($ctx->classes[$lc]) && !\in_array($name, $entry->interfaces, true)) {
                    $entry->interfaces[] = $name;
                }
            }

            $entry->constructor = new PharConstruct();
            $entry->methods['__construct'] = $entry->constructor;
            $entry->methodVisibility['__construct'] = $pub;
            $entry->methodNames['__construct'] = '__construct';
        }

        $methods = [
            'addfromstring' => [PharAddFromString::class, 'addFromString'],
            'addemptydir' => [PharAddEmptyDir::class, 'addEmptyDir'],
            'addfile' => [PharAddFile::class, 'addFile'],
            'buildfromdirectory' => [PharBuildFromDirectory::class, 'buildFromDirectory'],
            'buildfromiterator' => [PharBuildFromIterator::class, 'buildFromIterator'],
            'extractto' => [PharExtractTo::class, 'extractTo'],
            'setstub' => [PharSetStub::class, 'setStub'],
            'getstub' => [PharGetStub::class, 'getStub'],
            'setalias' => [PharSetAlias::class, 'setAlias'],
            'getalias' => [PharGetAlias::class, 'getAlias'],
            'startbuffering' => [PharStartBuffering::class, 'startBuffering'],
            'stopbuffering' => [PharStopBuffering::class, 'stopBuffering'],
            'isbuffering' => [PharIsBuffering::class, 'isBuffering'],
            'count' => [PharCount::class, 'count'],
            'delete' => [PharDelete::class, 'delete'],
            'hasmetadata' => [PharHasMetadata::class, 'hasMetadata'],
            'getmetadata' => [PharGetMetadata::class, 'getMetadata'],
            'setmetadata' => [PharSetMetadata::class, 'setMetadata'],
            'delmetadata' => [PharDelMetadata::class, 'delMetadata'],
            'getversion' => [PharGetVersion::class, 'getVersion'],
            'iswritable' => [PharIsWritable::class, 'isWritable'],
            'getmodified' => [PharGetModified::class, 'getModified'],
            'compressfiles' => [PharCompressFiles::class, 'compressFiles'],
            'getpath' => [PharGetPath::class, 'getPath'],
            'offsetset' => [PharOffsetSet::class, 'offsetSet'],
            'offsetget' => [PharOffsetGet::class, 'offsetGet'],
            'offsetexists' => [PharOffsetExists::class, 'offsetExists'],
            'offsetunset' => [PharOffsetUnset::class, 'offsetUnset'],
            'createdefaultstub' => [PharCreateDefaultStub::class, 'createDefaultStub'],
        ];
        $added = false;
        foreach ($methods as $lc => [$class, $name]) {
            if (isset($entry->methods[$lc])) {
                continue;
            }
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = 'createdefaultstub' === $lc ? $pubStatic : $pub;
            $entry->methodNames[$lc] = $name;
            $added = true;
        }
        if ($added || !isset($entry->methods['addfromstring'])) {
            $ctx->classes[VmPhar::CLASS_LC] = $entry;
        }
    }
}

final class PharConstruct extends VmClassMethod
{
    public function __construct() { parent::__construct('__construct'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('Phar::__construct() expects at least 1 argument, 0 given');
        }
        $object = VmPharArchive::requireReceiver($frame, 'Phar::__construct');
        $path = VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::__construct', 0, 'filename');
        $object->constructed = true;
        VmPharArchive::open($object, $path);
    }
}

final class PharAddFromString extends VmClassMethod
{
    public function __construct() { parent::__construct('addFromString'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf('Phar::addFromString() expects at least 2 arguments, %d given', \max(0, $argc - 1)));
        }
        $object = VmPharArchive::requireReceiver($frame, 'Phar::addFromString');
        VmPharArchive::addFromString(
            $object,
            VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::addFromString', 0, 'localname'),
            VmPharArchive::coercePathArg($frame->calledArgs[2], 'Phar::addFromString', 1, 'contents')
        );
    }
}

final class PharAddEmptyDir extends VmClassMethod
{
    public function __construct() { parent::__construct('addEmptyDir'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('Phar::addEmptyDir() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        VmPharArchive::addEmptyDir(
            VmPharArchive::requireReceiver($frame, 'Phar::addEmptyDir'),
            VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::addEmptyDir', 0, 'dirname')
        );
    }
}

final class PharAddFile extends VmClassMethod
{
    public function __construct() { parent::__construct('addFile'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('Phar::addFile() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $local = $argc >= 3
            ? VmPharArchive::coercePathArg($frame->calledArgs[2], 'Phar::addFile', 1, 'localname')
            : null;
        VmPharArchive::addFile(
            VmPharArchive::requireReceiver($frame, 'Phar::addFile'),
            VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::addFile', 0, 'filename'),
            $local
        );
    }
}

final class PharBuildFromDirectory extends VmClassMethod
{
    public function __construct() { parent::__construct('buildFromDirectory'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('Phar::buildFromDirectory() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $object = VmPharArchive::requireReceiver($frame, 'Phar::buildFromDirectory');
        $dir = VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::buildFromDirectory', 0, 'directory');
        $pattern = $argc >= 3
            ? VmPharArchive::coercePathArg($frame->calledArgs[2], 'Phar::buildFromDirectory', 1, 'pattern')
            : null;
        $map = VmPharArchive::buildFromDirectory($object, $dir, $pattern);
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(VmPharArchive::mapToHashTable($map));
        }
    }
}

final class PharBuildFromIterator extends VmClassMethod
{
    public function __construct() { parent::__construct('buildFromIterator'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('Phar::buildFromIterator() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $object = VmPharArchive::requireReceiver($frame, 'Phar::buildFromIterator');
        $iterVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $iterVar->type) {
            throw new \TypeError('Phar::buildFromIterator(): Argument #1 ($iterator) must be of type Traversable');
        }
        $base = $argc >= 3
            ? VmPharArchive::coercePathArg($frame->calledArgs[2], 'Phar::buildFromIterator', 1, 'baseDirectory')
            : null;
        $iterObj = $iterVar->toObject();
        $lc = \strtolower($iterObj->class->name);
        $pathMap = [];
        if (\in_array($lc, ['arrayiterator', 'arrayobject'], true)) {
            foreach (SplArrayStorage::getArrayCopy($iterObj)->iterateKeyed(true) as $key => $val) {
                $pathMap[\is_int($key) ? $key : (string) $key] = $val->toString();
            }
        } else {
            throw new \UnexpectedValueException('Phar::buildFromIterator() currently supports ArrayIterator/ArrayObject path maps');
        }
        $map = VmPharArchive::buildFromPathMap($object, $pathMap, $base);
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(VmPharArchive::mapToHashTable($map));
        }
    }
}

final class PharExtractTo extends VmClassMethod
{
    public function __construct() { parent::__construct('extractTo'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('Phar::extractTo() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $ok = VmPharArchive::extractTo(
            VmPharArchive::requireReceiver($frame, 'Phar::extractTo'),
            VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::extractTo', 0, 'directory')
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class PharSetStub extends VmClassMethod
{
    public function __construct() { parent::__construct('setStub'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('Phar::setStub() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $ok = VmPharArchive::setStub(
            VmPharArchive::requireReceiver($frame, 'Phar::setStub'),
            VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::setStub', 0, 'stub')
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class PharGetStub extends VmClassMethod
{
    public function __construct() { parent::__construct('getStub'); }
    public function execute(Frame $frame): void
    {
        $stub = VmPharArchive::getStub(VmPharArchive::requireReceiver($frame, 'Phar::getStub'));
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($stub);
        }
    }
}

final class PharSetAlias extends VmClassMethod
{
    public function __construct() { parent::__construct('setAlias'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('Phar::setAlias() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        $ok = VmPharArchive::setAlias(
            VmPharArchive::requireReceiver($frame, 'Phar::setAlias'),
            VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::setAlias', 0, 'alias')
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class PharGetAlias extends VmClassMethod
{
    public function __construct() { parent::__construct('getAlias'); }
    public function execute(Frame $frame): void
    {
        $alias = VmPharArchive::getAlias(VmPharArchive::requireReceiver($frame, 'Phar::getAlias'));
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $alias) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($alias);
    }
}

final class PharStartBuffering extends VmClassMethod
{
    public function __construct() { parent::__construct('startBuffering'); }
    public function execute(Frame $frame): void
    {
        VmPharArchive::startBuffering(VmPharArchive::requireReceiver($frame, 'Phar::startBuffering'));
    }
}

final class PharStopBuffering extends VmClassMethod
{
    public function __construct() { parent::__construct('stopBuffering'); }
    public function execute(Frame $frame): void
    {
        VmPharArchive::stopBuffering(VmPharArchive::requireReceiver($frame, 'Phar::stopBuffering'));
    }
}

/** Phar::isBuffering() — php-src zim_Phar_isBuffering (#21228). */
final class PharIsBuffering extends VmClassMethod
{
    public function __construct() { parent::__construct('isBuffering'); }
    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPharArchive::isBuffering(
                VmPharArchive::requireReceiver($frame, 'Phar::isBuffering')
            ));
        }
    }
}

/** Phar::count() — php-src zim_Phar_count / Countable (#21228). */
final class PharCount extends VmClassMethod
{
    public function __construct() { parent::__construct('count'); }
    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmPharArchive::count(
                VmPharArchive::requireReceiver($frame, 'Phar::count')
            ));
        }
    }
}

/** Phar::delete() — php-src zim_Phar_delete (#21228). */
final class PharDelete extends VmClassMethod
{
    public function __construct() { parent::__construct('delete'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('Phar::delete() expects exactly 1 argument, %d given', \max(0, $argc - 1)));
        }
        $ok = VmPharArchive::delete(
            VmPharArchive::requireReceiver($frame, 'Phar::delete'),
            VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::delete', 0, 'localname')
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/** Phar::hasMetadata() — php-src zim_Phar_hasMetadata (#21229). */
final class PharHasMetadata extends VmClassMethod
{
    public function __construct() { parent::__construct('hasMetadata'); }
    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPharArchive::hasMetadata(
                VmPharArchive::requireReceiver($frame, 'Phar::hasMetadata')
            ));
        }
    }
}

/** Phar::getMetadata() — php-src zim_Phar_getMetadata (#21229). */
final class PharGetMetadata extends VmClassMethod
{
    public function __construct() { parent::__construct('getMetadata'); }
    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $value = VmPharArchive::getMetadata(VmPharArchive::requireReceiver($frame, 'Phar::getMetadata'));
        $imported = VmJson::import($value);
        $frame->returnVar->copyFrom($imported);
    }
}

/** Phar::setMetadata() — php-src zim_Phar_setMetadata (#21229). */
final class PharSetMetadata extends VmClassMethod
{
    public function __construct() { parent::__construct('setMetadata'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('Phar::setMetadata() expects exactly 1 argument, %d given', \max(0, $argc - 1)));
        }
        $meta = VmJson::export($frame->calledArgs[1]->resolveIndirect(), $frame->vmContext, null, $frame);
        VmPharArchive::setMetadata(VmPharArchive::requireReceiver($frame, 'Phar::setMetadata'), $meta);
    }
}

/** Phar::delMetadata() — php-src zim_Phar_delMetadata (#21229). */
final class PharDelMetadata extends VmClassMethod
{
    public function __construct() { parent::__construct('delMetadata'); }
    public function execute(Frame $frame): void
    {
        $ok = VmPharArchive::delMetadata(VmPharArchive::requireReceiver($frame, 'Phar::delMetadata'));
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/** Phar::getVersion() — php-src zim_Phar_getVersion (#21230). */
final class PharGetVersion extends VmClassMethod
{
    public function __construct() { parent::__construct('getVersion'); }
    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmPharArchive::getVersion(
                VmPharArchive::requireReceiver($frame, 'Phar::getVersion')
            ));
        }
    }
}

/** Phar::isWritable() — php-src zim_Phar_isWritable (#21230). */
final class PharIsWritable extends VmClassMethod
{
    public function __construct() { parent::__construct('isWritable'); }
    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPharArchive::isWritable(
                VmPharArchive::requireReceiver($frame, 'Phar::isWritable')
            ));
        }
    }
}

/** Phar::getModified() — php-src zim_Phar_getModified (#21230). */
final class PharGetModified extends VmClassMethod
{
    public function __construct() { parent::__construct('getModified'); }
    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPharArchive::getModified(
                VmPharArchive::requireReceiver($frame, 'Phar::getModified')
            ));
        }
    }
}

final class PharCompressFiles extends VmClassMethod
{
    public function __construct() { parent::__construct('compressFiles'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf('Phar::compressFiles() expects at least 1 argument, %d given', \max(0, $argc - 1)));
        }
        VmPharArchive::compressFiles(
            VmPharArchive::requireReceiver($frame, 'Phar::compressFiles'),
            $frame->calledArgs[1]->resolveIndirect()->toInt()
        );
    }
}

final class PharGetPath extends VmClassMethod
{
    public function __construct() { parent::__construct('getPath'); }
    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmPharArchive::path(VmPharArchive::requireReceiver($frame, 'Phar::getPath')));
        }
    }
}

final class PharOffsetSet extends VmClassMethod
{
    public function __construct() { parent::__construct('offsetSet'); }
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('Phar::offsetSet() expects exactly 2 arguments');
        }
        VmPharArchive::offsetSet(
            VmPharArchive::requireReceiver($frame, 'Phar::offsetSet'),
            VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::offsetSet', 0, 'localname'),
            VmPharArchive::coercePathArg($frame->calledArgs[2], 'Phar::offsetSet', 1, 'value')
        );
    }
}

final class PharOffsetGet extends VmClassMethod
{
    public function __construct() { parent::__construct('offsetGet'); }
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Phar::offsetGet() expects exactly 1 argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $var = VmPharArchive::offsetGet(
            VmPharArchive::requireReceiver($frame, 'Phar::offsetGet'),
            VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::offsetGet', 0, 'localname'),
            $frame->vmContext
        );
        $frame->returnVar->object($var->toObject());
    }
}

final class PharOffsetExists extends VmClassMethod
{
    public function __construct() { parent::__construct('offsetExists'); }
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Phar::offsetExists() expects exactly 1 argument');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPharArchive::offsetExists(
                VmPharArchive::requireReceiver($frame, 'Phar::offsetExists'),
                VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::offsetExists', 0, 'localname')
            ));
        }
    }
}

final class PharOffsetUnset extends VmClassMethod
{
    public function __construct() { parent::__construct('offsetUnset'); }
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Phar::offsetUnset() expects exactly 1 argument');
        }
        VmPharArchive::offsetUnset(
            VmPharArchive::requireReceiver($frame, 'Phar::offsetUnset'),
            VmPharArchive::coercePathArg($frame->calledArgs[1], 'Phar::offsetUnset', 0, 'localname')
        );
    }
}

final class PharCreateDefaultStub extends VmClassMethod
{
    public function __construct() { parent::__construct('createDefaultStub'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // static: calledArgs[0] may be class name string for static call — Phar::createDefaultStub()
        // VmClassMethod static calls typically pass no $this; check how Phar::canWrite works (argc can be 0).
        $index = null;
        $web = null;
        $offset = 0;
        if ($argc >= 1 && Variable::TYPE_OBJECT === $frame->calledArgs[0]->resolveIndirect()->type
            && 'phar' === \strtolower($frame->calledArgs[0]->resolveIndirect()->toObject()->class->name)) {
            $offset = 1;
        }
        if ($argc > $offset) {
            $index = $frame->calledArgs[$offset]->resolveIndirect()->toString();
        }
        if ($argc > $offset + 1) {
            $web = $frame->calledArgs[$offset + 1]->resolveIndirect()->toString();
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmPharArchive::createDefaultStub($index, $web));
        }
    }
}
