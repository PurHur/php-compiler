<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Directory internal class + dir() handle (php-src ext/standard/dir.c; #13368).
 */
final class DirectoryBuiltin
{
    public const CLASS_LC = 'directory';

    public const PROP_PATH = 'path';

    public const PROP_HANDLE = 'handle';

    /** @var array<int, array{handle: int, path: string, closed: bool}> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $strProto = new Variable(Variable::TYPE_STRING);
        $handleProto = new Variable(Variable::TYPE_OBJECT);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('Directory');
        $entry->properties[] = new ClassProperty(self::PROP_PATH, null, $strProto);
        $entry->properties[] = new ClassProperty(self::PROP_HANDLE, null, $handleProto);

        $entry->constructor = new DirectoryConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'read' => DirectoryRead::class,
            'rewind' => DirectoryRewind::class,
            'close' => DirectoryClose::class,
        ] as $name => $class) {
            $entry->methods[$name] = new $class();
            $entry->methodVisibility[$name] = $pub;
        }

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function fromOpendir(Context $ctx, string $path, int $handle): Variable
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('Directory is not registered in this compiler build');
        }

        $object = new ObjectEntry($class);
        self::$store[$object->id] = [
            'handle' => $handle,
            'path' => $path,
            'closed' => false,
        ];
        $object->getProperty(self::PROP_PATH)->string($path);
        $object->getProperty(self::PROP_HANDLE)->dirHandle($handle, $ctx);
        $object->constructed = true;

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function requireReceiver(Variable $var, string $method): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s must be called on Directory, %s given',
                $method,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf(
                '%s must be called on Directory, %s given',
                $method,
                $object->class->name
            ));
        }

        return $object;
    }

    /** @return array{handle: int, path: string, closed: bool} */
    public static function state(ObjectEntry $object): array
    {
        $state = self::$store[$object->id] ?? null;
        if (null === $state) {
            throw new \LogicException('Directory internal state missing in this compiler build');
        }

        return $state;
    }

    public static function requireOpenHandle(ObjectEntry $object, string $method): int
    {
        $state = self::state($object);
        if ($state['closed'] || !VmDir::isValidHandle($state['handle'])) {
            throw new \TypeError(\sprintf(
                'Directory::%s(): supplied resource is not a valid Directory resource',
                $method
            ));
        }

        return $state['handle'];
    }

    public static function closeObject(ObjectEntry $object, Context $ctx): void
    {
        $state = self::state($object);
        if (!$state['closed'] && VmDir::isValidHandle($state['handle'])) {
            VmDir::closedir($state['handle']);
        }
        self::$store[$object->id]['closed'] = true;
        $object->getProperty(self::PROP_HANDLE)->bool(false);
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['read'], $entry->methods['rewind'], $entry->methods['close']);
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }
}

abstract class DirectoryMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return DirectoryBuiltin::requireReceiver($frame->calledArgs[0], $label);
    }

    /** php-src ext/standard/dir.c — ZEND_PARSE_PARAMETERS_NONE (#30946). */
    protected function requireNoUserArgs(Frame $frame, string $method): void
    {
        $this->requireExactUserArgCount($frame, 'Directory::'.$method, 0);
    }
}

final class DirectoryConstruct extends DirectoryMethod
{
    public function execute(Frame $frame): void
    {
        throw new \Error('Cannot directly construct Directory, use dir() instead');
    }
}

final class DirectoryRead extends DirectoryMethod
{
    public function execute(Frame $frame): void
    {
        $this->requireNoUserArgs($frame, 'read');
        $object = $this->receiver($frame, 'Directory::read()');
        if (null === $frame->returnVar) {
            return;
        }
        $handle = DirectoryBuiltin::requireOpenHandle($object, 'read');
        $entry = VmDir::readdir($handle);
        if (false === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($entry);
    }
}

final class DirectoryRewind extends DirectoryMethod
{
    public function execute(Frame $frame): void
    {
        $this->requireNoUserArgs($frame, 'rewind');
        $object = $this->receiver($frame, 'Directory::rewind()');
        $handle = DirectoryBuiltin::requireOpenHandle($object, 'rewind');
        VmDir::rewinddir($handle);
    }
}

final class DirectoryClose extends DirectoryMethod
{
    public function execute(Frame $frame): void
    {
        $this->requireNoUserArgs($frame, 'close');
        $object = $this->receiver($frame, 'Directory::close()');
        if (null === $frame->vmContext) {
            throw new \LogicException('Directory::close() requires VM context in this compiler build');
        }
        DirectoryBuiltin::closeObject($object, $frame->vmContext);
    }
}
