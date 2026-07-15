<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\standard\VmFsUnlink;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\ext\standard\VmStatPath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func;

/**
 * SessionHandler — stock files save-handler object (php-src ext/session/mod_user_class.c; #19246).
 *
 * Methods forward to the default files module ({@see SessionFileStorage} / {@see VmSession}).
 */
final class SessionHandlerBuiltin
{
    public const CLASS_LC = 'sessionhandler';

    /** php-src PS(mod_user_is_open) for SessionHandler::* parent handlers. */
    private static bool $defaultHandlerOpen = false;

    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SessionHandler');
        $entry->isInternal = true;
        $entry->interfaces = ['sessionhandlerinterface', 'sessionidinterface'];
        $pub = Func::FLAG_PUBLIC;
        foreach ([
            'open' => SessionHandlerOpen::class,
            'close' => SessionHandlerClose::class,
            'read' => SessionHandlerRead::class,
            'write' => SessionHandlerWrite::class,
            'destroy' => SessionHandlerDestroy::class,
            'gc' => SessionHandlerGc::class,
            'create_sid' => SessionHandlerCreateSid::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['create_sid'] = 'create_sid';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function reset(): void
    {
        self::$defaultHandlerOpen = false;
    }

    public static function receiver(Frame $frame, string $signature): ObjectEntry
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \LogicException($signature.' requires object receiver');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError($signature.' must be called on SessionHandler instance');
        }
        $object = $receiver->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError($signature.' must be called on SessionHandler instance');
        }

        return $object;
    }

    public static function assertSessionActive(): void
    {
        if (!VmSession::isActive()) {
            throw new \Error('Session is not active');
        }
    }

    /**
     * php-src PS_SANITY_CHECK_IS_OPEN — warn + false when parent files handler is not open.
     */
    public static function requireDefaultHandlerOpen(Frame $frame, string $method): bool
    {
        self::assertSessionActive();
        if (self::$defaultHandlerOpen) {
            return true;
        }
        self::warnParentNotOpen($frame, $method);

        return false;
    }

    public static function warnParentNotOpen(Frame $frame, string $method): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            'SessionHandler::'.$method.'(): Parent session handler is not open',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    public static function markDefaultHandlerOpen(bool $open): void
    {
        self::$defaultHandlerOpen = $open;
    }

    public static function isDefaultHandlerOpen(): bool
    {
        return self::$defaultHandlerOpen;
    }

    public static function openFiles(string $savePath, string $sessionName): bool
    {
        unset($sessionName);
        $dir = '' !== $savePath ? rtrim($savePath, '/\\') : SessionFileStorage::storageDir();
        if (!VmStatPath::isDir($dir)) {
            if (!VmFs::mkdir($dir, 0700, true)) {
                return false;
            }
        }
        self::$defaultHandlerOpen = true;

        return true;
    }

    /**
     * @return string|false
     */
    public static function readFiles(string $id)
    {
        $id = SessionFileStorage::sanitizeId($id);
        if ('' === $id) {
            return false;
        }
        $path = SessionFileStorage::storagePath($id);
        if (!VmStatPath::isFile($path)) {
            return '';
        }
        $raw = VmFsReadNative::read($path);
        if (false === $raw) {
            return false;
        }

        return $raw;
    }

    public static function writeFiles(string $id, string $data): bool
    {
        $id = SessionFileStorage::sanitizeId($id);
        if ('' === $id) {
            return false;
        }
        $dir = SessionFileStorage::storageDir();
        if (!VmStatPath::isDir($dir)) {
            VmFs::mkdir($dir, 0700, true);
        }

        return false !== VmFs::filePutContents(SessionFileStorage::storagePath($id), $data, \LOCK_EX);
    }

    public static function destroyFiles(string $id): bool
    {
        $id = SessionFileStorage::sanitizeId($id);
        if ('' === $id) {
            return false;
        }
        $path = SessionFileStorage::storagePath($id);
        if (VmStatPath::isFile($path)) {
            return VmFsUnlink::unlink($path);
        }

        return true;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['open'],
            $entry->methods['close'],
            $entry->methods['read'],
            $entry->methods['write'],
            $entry->methods['destroy'],
            $entry->methods['gc'],
            $entry->methods['create_sid']
        );
    }
}

final class SessionHandlerOpen extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('open');
    }

    public function execute(Frame $frame): void
    {
        SessionHandlerBuiltin::receiver($frame, 'SessionHandler::open()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(\sprintf(
                'SessionHandler::open() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        if (\count($frame->calledArgs) > 3) {
            throw new \ArgumentCountError(\sprintf(
                'SessionHandler::open() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        SessionHandlerBuiltin::assertSessionActive();
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'SessionHandler::open', 0, 'path');
        $name = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'SessionHandler::open', 1, 'name');
        $ok = SessionHandlerBuiltin::openFiles($path, $name);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

final class SessionHandlerClose extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        SessionHandlerBuiltin::receiver($frame, 'SessionHandler::close()');
        if (!SessionHandlerBuiltin::requireDefaultHandlerOpen($frame, 'close')) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
        SessionHandlerBuiltin::markDefaultHandlerOpen(false);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
            $ret->bool(true);
        });
    }
}

final class SessionHandlerRead extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('read');
    }

    public function execute(Frame $frame): void
    {
        SessionHandlerBuiltin::receiver($frame, 'SessionHandler::read()');
        if (!isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError('SessionHandler::read() expects exactly 1 argument, 0 given');
        }
        if (\count($frame->calledArgs) > 2) {
            throw new \ArgumentCountError(\sprintf(
                'SessionHandler::read() expects exactly 1 argument, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        if (!SessionHandlerBuiltin::requireDefaultHandlerOpen($frame, 'read')) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
        $id = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'SessionHandler::read', 0, 'id');
        $raw = SessionHandlerBuiltin::readFiles($id);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($raw): void {
            if (false === $raw) {
                $ret->bool(false);

                return;
            }
            $ret->string($raw);
        });
    }
}

final class SessionHandlerWrite extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('write');
    }

    public function execute(Frame $frame): void
    {
        SessionHandlerBuiltin::receiver($frame, 'SessionHandler::write()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(\sprintf(
                'SessionHandler::write() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        if (\count($frame->calledArgs) > 3) {
            throw new \ArgumentCountError(\sprintf(
                'SessionHandler::write() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        if (!SessionHandlerBuiltin::requireDefaultHandlerOpen($frame, 'write')) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
        $id = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'SessionHandler::write', 0, 'id');
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'SessionHandler::write', 1, 'data');
        $ok = SessionHandlerBuiltin::writeFiles($id, $data);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

final class SessionHandlerDestroy extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('destroy');
    }

    public function execute(Frame $frame): void
    {
        SessionHandlerBuiltin::receiver($frame, 'SessionHandler::destroy()');
        if (!isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError('SessionHandler::destroy() expects exactly 1 argument, 0 given');
        }
        if (\count($frame->calledArgs) > 2) {
            throw new \ArgumentCountError(\sprintf(
                'SessionHandler::destroy() expects exactly 1 argument, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        if (!SessionHandlerBuiltin::requireDefaultHandlerOpen($frame, 'destroy')) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
        $id = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'SessionHandler::destroy', 0, 'id');
        $ok = SessionHandlerBuiltin::destroyFiles($id);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

final class SessionHandlerGc extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('gc');
    }

    public function execute(Frame $frame): void
    {
        SessionHandlerBuiltin::receiver($frame, 'SessionHandler::gc()');
        if (!isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError('SessionHandler::gc() expects exactly 1 argument, 0 given');
        }
        if (\count($frame->calledArgs) > 2) {
            throw new \ArgumentCountError(\sprintf(
                'SessionHandler::gc() expects exactly 1 argument, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        if (!SessionHandlerBuiltin::requireDefaultHandlerOpen($frame, 'gc')) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
        $maxLifetime = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'SessionHandler::gc',
            0,
            'max_lifetime'
        );
        $deleted = VmSession::gcExpiredFiles($maxLifetime);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($deleted): void {
            if (false === $deleted) {
                $ret->bool(false);

                return;
            }
            $ret->int($deleted);
        });
    }
}

final class SessionHandlerCreateSid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('create_sid');
    }

    public function execute(Frame $frame): void
    {
        SessionHandlerBuiltin::receiver($frame, 'SessionHandler::create_sid()');
        SessionHandlerBuiltin::assertSessionActive();
        $id = VmSession::createId(null);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($id): void {
            if (false === $id) {
                $ret->bool(false);

                return;
            }
            $ret->string($id);
        });
    }
}
