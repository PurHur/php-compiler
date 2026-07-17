<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ShutdownQueue;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmSession;

/**
 * Userspace session save handler dispatch (php-src ext/session/mod_user.c; #4873).
 */
final class SessionUserHandler
{
    private const USER_MODULE = 'user';

    private static ?ObjectEntry $handler = null;

    private static bool $opened = false;

    private static bool $shutdownRegistered = false;

    public static function reset(): void
    {
        self::$handler = null;
        self::$opened = false;
        self::$shutdownRegistered = false;
    }

    public static function hasHandler(): bool
    {
        return null !== self::$handler;
    }

    public static function isActiveModule(): bool
    {
        return self::USER_MODULE === VmSession::getModuleName() && self::hasHandler();
    }

    /**
     * @return true when handler installed and module switched to user
     */
    public static function install(ObjectEntry $handler, bool $registerShutdown): bool
    {
        self::$handler = $handler;
        self::$opened = false;
        VmSession::setModuleName(self::USER_MODULE);
        if ($registerShutdown) {
            self::registerShutdown();
        }

        return true;
    }

    public static function registerShutdown(): bool
    {
        if (self::$shutdownRegistered) {
            return true;
        }
        ShutdownQueue::registerSessionWriteClose();
        self::$shutdownRegistered = true;

        return true;
    }

    public static function open(Context $ctx): bool
    {
        if (!self::isActiveModule() || self::$opened) {
            return true;
        }
        $vm = $ctx->runtime->vm;
        $pathVar = new Variable();
        $pathVar->string(VmSession::getSavePath());
        $nameVar = new Variable();
        $nameVar->string(VmSession::getName());
        $result = $vm->invokeInstanceMethod(self::$handler, 'open', $pathVar, $nameVar)->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $result->type || !$result->toBool()) {
            return false;
        }
        self::$opened = true;

        return true;
    }

    public static function read(Context $ctx, string $id): string
    {
        if (!self::isActiveModule()) {
            return '';
        }
        if (!self::$opened && !self::open($ctx)) {
            return '';
        }
        $vm = $ctx->runtime->vm;
        $idVar = new Variable();
        $idVar->string($id);
        $result = $vm->invokeInstanceMethod(self::$handler, 'read', $idVar)->resolveIndirect();
        if (Variable::TYPE_STRING === $result->type) {
            return $result->toString();
        }
        if (Variable::TYPE_BOOLEAN === $result->type && false === $result->toBool()) {
            return '';
        }

        return '';
    }

    public static function write(Context $ctx, string $id, string $data): bool
    {
        if (!self::isActiveModule()) {
            return false;
        }
        if (!self::$opened && !self::open($ctx)) {
            return false;
        }
        $vm = $ctx->runtime->vm;
        $idVar = new Variable();
        $idVar->string($id);
        $dataVar = new Variable();
        $dataVar->string($data);
        $result = $vm->invokeInstanceMethod(self::$handler, 'write', $idVar, $dataVar)->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    public static function close(Context $ctx): bool
    {
        if (!self::isActiveModule() || !self::$opened) {
            return true;
        }
        $vm = $ctx->runtime->vm;
        $result = $vm->invokeInstanceMethod(self::$handler, 'close')->resolveIndirect();
        self::$opened = false;
        if (Variable::TYPE_BOOLEAN !== $result->type) {
            return false;
        }

        return $result->toBool();
    }

    public static function destroy(Context $ctx, string $id): bool
    {
        if (!self::isActiveModule()) {
            return false;
        }
        if (!self::$opened && !self::open($ctx)) {
            return false;
        }
        $vm = $ctx->runtime->vm;
        $idVar = new Variable();
        $idVar->string($id);
        $result = $vm->invokeInstanceMethod(self::$handler, 'destroy', $idVar)->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    /**
     * @return int|false
     */
    public static function gc(Context $ctx, int $maxLifetime)
    {
        if (!self::isActiveModule()) {
            return false;
        }
        if (!self::$opened && !self::open($ctx)) {
            return false;
        }
        $vm = $ctx->runtime->vm;
        $maxVar = new Variable();
        $maxVar->int($maxLifetime);
        $result = $vm->invokeInstanceMethod(self::$handler, 'gc', $maxVar)->resolveIndirect();
        if (Variable::TYPE_INTEGER === $result->type) {
            return $result->toInt();
        }
        if (Variable::TYPE_BOOLEAN === $result->type && false === $result->toBool()) {
            return false;
        }

        return false;
    }

    public static function validateId(Context $ctx, string $id): bool
    {
        if (!self::isActiveModule()) {
            return false;
        }
        $vm = $ctx->runtime->vm;
        if (!$vm->hasInstanceMethod(self::$handler->class, 'validate_sid')) {
            return true;
        }
        if (!self::$opened && !self::open($ctx)) {
            return false;
        }
        $idVar = new Variable();
        $idVar->string($id);
        $result = $vm->invokeInstanceMethod(self::$handler, 'validate_sid', $idVar)->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    public static function requireHandlerObject(Variable $var, string $function): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            $given = match ($var->type) {
                Variable::TYPE_STRING => 'string',
                Variable::TYPE_INTEGER => 'int',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_BOOLEAN => 'bool',
                Variable::TYPE_NULL => 'null',
                Variable::TYPE_ARRAY => 'array',
                default => 'mixed',
            };
            throw new \TypeError(
                $function.'(): Argument #1 ($sessionhandler) must be of type object, '.$given.' given'
            );
        }

        return $var->toObject();
    }

    public static function assertSessionHandlerInterface(
        Context $context,
        ObjectEntry $handler,
        string $function
    ): void {
        if (!InterfaceCheck::entryImplements($handler->class, 'sessionhandlerinterface', $context)) {
            throw new \TypeError(
                $function.'(): Argument #1 ($open) must be of type SessionHandlerInterface, '
                .$handler->class->name.' given'
            );
        }
    }

    public static function assertHandlerMethods(VM $vm, ObjectEntry $handler, string $function): void
    {
        foreach (['open', 'close', 'read', 'write', 'destroy', 'gc'] as $method) {
            if (!$vm->hasInstanceMethod($handler->class, $method)) {
                throw new \LogicException(
                    $function.'(): User save handler must implement '.$method.'()'
                );
            }
        }
    }
}
