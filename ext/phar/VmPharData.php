<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStatPath;
use PHPCompiler\ext\standard\VmString;

/**
 * PharData archive state — php-src ext/phar/phar_object.c PharData (#6490).
 */
final class VmPharData
{
    public const CLASS_LC = 'phardata';

    /** @var array<int, array{path: string, files: array<string, string>, dirty: bool}> */
    private static array $state = [];

    public static function bind(ObjectEntry $object, string $path, array $files, bool $dirty): void
    {
        self::$state[$object->id] = [
            'path' => $path,
            'files' => $files,
            'dirty' => $dirty,
        ];
    }

    public static function open(ObjectEntry $object, string $path): void
    {
        $path = \str_replace('\\', '/', $path);
        if (VmStatPath::isFile($path)) {
            $binary = VmFs::fileGetContents($path);
            if (false === $binary) {
                throw new \UnexpectedValueException(
                    'phar error: unable to open phar "'.$path.'"'
                );
            }
            $files = VmPharTar::readArchive($binary);
            self::bind($object, $path, $files, false);

            return;
        }

        // Create empty archive path (Zend creates on first write).
        $dir = \dirname($path);
        if ('.' !== $dir && !VmStatPath::isDir($dir) && !VmStatPath::isFile($dir)) {
            throw new \UnexpectedValueException(
                'phar error: unable to create phar "'.$path.'"'
            );
        }
        self::bind($object, $path, [], true);
    }

    public static function addFromString(ObjectEntry $object, string $localname, string $contents): void
    {
        $st = self::requireState($object);
        $localname = \ltrim(\str_replace('\\', '/', $localname), '/');
        if ('' === $localname) {
            throw new \UnexpectedValueException('Entry name cannot be empty');
        }
        self::$state[$object->id]['files'][$localname] = $contents;
        self::$state[$object->id]['dirty'] = true;
        self::flush($object);
    }

    public static function extractTo(ObjectEntry $object, string $directory): bool
    {
        $st = self::requireState($object);
        $directory = \rtrim(\str_replace('\\', '/', $directory), '/');
        if (!VmStatPath::isDir($directory) && !VmFs::mkdir($directory, 0777, true) && !VmStatPath::isDir($directory)) {
            return false;
        }
        foreach ($st['files'] as $name => $contents) {
            $target = $directory.'/'.$name;
            $parent = \dirname($target);
            if (!VmStatPath::isDir($parent)) {
                VmFs::mkdir($parent, 0777, true);
            }
            if (false === VmFs::filePutContents($target, $contents)) {
                return false;
            }
        }

        return true;
    }

    public static function offsetSet(ObjectEntry $object, string $localname, string $contents): void
    {
        self::addFromString($object, $localname, $contents);
    }

    public static function offsetExists(ObjectEntry $object, string $localname): bool
    {
        $st = self::requireState($object);
        $localname = \ltrim(\str_replace('\\', '/', $localname), '/');

        return isset($st['files'][$localname]);
    }

    public static function offsetGet(ObjectEntry $object, string $localname, Context $ctx): Variable
    {
        $st = self::requireState($object);
        $localname = \ltrim(\str_replace('\\', '/', $localname), '/');
        if (!isset($st['files'][$localname])) {
            throw new \BadMethodCallException('Entry '.$localname.' does not exist');
        }
        $var = new Variable(Variable::TYPE_OBJECT);
        $info = VmPharFileInfo::createFromEntry($ctx, $st['path'], $localname, $st['files'][$localname]);
        $var->object($info);

        return $var;
    }

    public static function fileInfoContent(ObjectEntry $info): string
    {
        return VmPharFileInfo::state($info)['content'];
    }

    public static function requireReceiver(Frame $frame, string $method): ObjectEntry
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \Error($method.'(): must be called on PharData object');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError($method.'(): must be called on PharData object');
        }
        $object = $receiver->toObject();
        if (self::CLASS_LC !== \strtolower($object->class->name)) {
            throw new \TypeError($method.'(): must be called on PharData object');
        }

        return $object;
    }

    public static function coercePathArg(Variable $operand, string $function, int $argIndex, string $param): string
    {
        return VmString::coerceStringBuiltinArg($operand, $function, $argIndex, $param);
    }

    private static function flush(ObjectEntry $object): void
    {
        $st = self::requireState($object);
        if (!$st['dirty']) {
            return;
        }
        $binary = VmPharTar::writeArchive($st['files']);
        if (false === VmFs::filePutContents($st['path'], $binary)) {
            throw new \UnexpectedValueException(
                'phar error: unable to write phar "'.$st['path'].'"'
            );
        }
        self::$state[$object->id]['dirty'] = false;
    }

    /** @return array{path: string, files: array<string, string>, dirty: bool} */
    private static function requireState(ObjectEntry $object): array
    {
        if (!isset(self::$state[$object->id])) {
            throw new \Error('PharData is uninitialized');
        }

        return self::$state[$object->id];
    }
}
