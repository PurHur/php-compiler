<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\VM;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ShutdownQueue;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\ext\standard\VmSession;

/**
 * Userspace session save handler dispatch (php-src ext/session/mod_user.c; #4873, #21136).
 *
 * Supports the object {@see SessionHandlerInterface} form and the legacy 6–9 callable form.
 */
final class SessionUserHandler
{
    private const USER_MODULE = 'user';

    /** @var list<string> */
    private const CALLBACK_PARAMS = [
        'open',
        'close',
        'read',
        'write',
        'destroy',
        'gc',
        'create_sid',
        'validate_sid',
        'update_timestamp',
    ];

    private static ?ObjectEntry $handler = null;

    /**
     * Procedural handlers — ClosureState kept by value so temp call-arg objects can die (#21136).
     *
     * @var list<array{kind: 'closure', closure: ClosureState}|array{kind: 'callable', callable: Variable}>|null
     */
    private static ?array $callbacks = null;

    private static bool $opened = false;

    private static bool $shutdownRegistered = false;

    public static function reset(): void
    {
        self::$handler = null;
        self::$callbacks = null;
        self::$opened = false;
        self::$shutdownRegistered = false;
    }

    public static function hasHandler(): bool
    {
        return null !== self::$handler || null !== self::$callbacks;
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
        self::$callbacks = null;
        self::$opened = false;
        VmSession::setModuleName(self::USER_MODULE);
        if ($registerShutdown) {
            self::registerShutdown();
        } else {
            self::clearShutdown();
        }

        return true;
    }

    /**
     * Procedural 6–9 callable save handler (php-src session_set_save_handler argc≥6).
     *
     * @param list<array{kind: 'closure', closure: ClosureState}|array{kind: 'callable', callable: Variable}> $callbacks
     */
    public static function installCallables(array $callbacks): bool
    {
        self::$handler = null;
        self::$callbacks = $callbacks;
        self::$opened = false;
        VmSession::setModuleName(self::USER_MODULE);
        // php-src removes session_shutdown for the callable form (no auto register_shutdown).
        self::clearShutdown();

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

    public static function clearShutdown(): void
    {
        ShutdownQueue::clearSessionWriteClose();
        self::$shutdownRegistered = false;
    }

    public static function open(Context $ctx): bool
    {
        if (!self::isActiveModule() || self::$opened) {
            return true;
        }
        $pathVar = new Variable();
        $pathVar->string(VmSession::getSavePath());
        $nameVar = new Variable();
        $nameVar->string(VmSession::getName());
        $result = self::dispatch($ctx, 0, 'open', $pathVar, $nameVar);
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
        $idVar = new Variable();
        $idVar->string($id);
        $result = self::dispatch($ctx, 2, 'read', $idVar);
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
        $idVar = new Variable();
        $idVar->string($id);
        $dataVar = new Variable();
        $dataVar->string($data);
        $result = self::dispatch($ctx, 3, 'write', $idVar, $dataVar);

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    public static function close(Context $ctx): bool
    {
        if (!self::isActiveModule() || !self::$opened) {
            return true;
        }
        $result = self::dispatch($ctx, 1, 'close');
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
        $idVar = new Variable();
        $idVar->string($id);
        $result = self::dispatch($ctx, 4, 'destroy', $idVar);

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
        $maxVar = new Variable();
        $maxVar->int($maxlifetime);
        $result = self::dispatch($ctx, 5, 'gc', $maxVar);
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
        if (null !== self::$callbacks) {
            if (!isset(self::$callbacks[7])) {
                return true;
            }
            // php-src PS_VALIDATE_SID_FUNC(user) calls validate_sid without requiring open first.
            $idVar = new Variable();
            $idVar->string($id);
            $result = self::invokeStored($ctx, self::$callbacks[7], $idVar);

            return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
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

    /**
     * Optional create_sid callback (php-src PS_CREATE_SID_FUNC(user) when set via 7-arg form).
     *
     * Object handlers keep the pre-#21136 path (php_session_create_id) — SessionHandler::create_sid
     * requires an active session and must not run from generateId() before status flips active.
     *
     * @return string|null null → fall back to php_session_create_id
     */
    public static function createSid(Context $ctx): ?string
    {
        if (!self::isActiveModule() || null === self::$callbacks || !isset(self::$callbacks[6])) {
            return null;
        }
        $result = self::invokeStored($ctx, self::$callbacks[6]);
        if (Variable::TYPE_STRING === $result->type) {
            return $result->toString();
        }

        return null;
    }

    public static function hasUpdateTimestamp(): bool
    {
        return null !== self::$callbacks && isset(self::$callbacks[8]);
    }

    /**
     * Optional update_timestamp (php-src PS_UPDATE_TIMESTAMP_FUNC(user); #21156).
     */
    public static function updateTimestamp(Context $ctx, string $id, string $data): bool
    {
        if (!self::isActiveModule() || null === self::$callbacks || !isset(self::$callbacks[8])) {
            return self::write($ctx, $id, $data);
        }
        $idVar = new Variable();
        $idVar->string($id);
        $dataVar = new Variable();
        $dataVar->string($data);
        $result = self::invokeStored($ctx, self::$callbacks[8], $idVar, $dataVar);

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    /**
     * Assert each of argc callables (php-src zend_is_callable loop; #21136).
     *
     * @param list<Variable> $args
     * @return list<array{kind: 'closure', closure: ClosureState}|array{kind: 'callable', callable: Variable}>
     */
    public static function requireCallableArgs(Context $ctx, array $args): array
    {
        $out = [];
        foreach ($args as $i => $arg) {
            $out[] = self::requireCallableArg($ctx, $arg, $i + 1, self::CALLBACK_PARAMS[$i]);
        }

        return $out;
    }

    /**
     * @return array{kind: 'closure', closure: ClosureState}|array{kind: 'callable', callable: Variable}
     */
    public static function requireCallableArg(
        Context $ctx,
        Variable $var,
        int $argNum,
        string $paramName
    ): array {
        $var = $var->resolveIndirect();
        // Extract ClosureState now — temp call-arg ObjectEntries die when the builtin returns
        // (same pattern as register_shutdown_function; #21136).
        if (VmClosureCall::isClosure($var)) {
            return ['kind' => 'closure', 'closure' => VmClosureCall::resolve($var)];
        }
        $nameOut = new Variable();
        if (VmCallable::isCallable($ctx, $var, false, $nameOut)) {
            $copy = new Variable();
            $copy->copyFrom($var);

            return ['kind' => 'callable', 'callable' => $copy];
        }
        $name = Variable::TYPE_STRING === $nameOut->type ? $nameOut->toString() : '';
        throw new \TypeError(
            'session_set_save_handler(): Argument #'.$argNum.' ($'.$paramName
            .') must be a valid callback, function "'.$name.'" not found or invalid function name'
        );
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

    private static function dispatch(Context $ctx, int $callbackIndex, string $method, Variable ...$args): Variable
    {
        if (null !== self::$callbacks) {
            return self::invokeStored($ctx, self::$callbacks[$callbackIndex], ...$args);
        }

        return $ctx->runtime->vm->invokeInstanceMethod(self::$handler, $method, ...$args)->resolveIndirect();
    }

    /**
     * @param array{kind: 'closure', closure: ClosureState}|array{kind: 'callable', callable: Variable} $entry
     */
    private static function invokeStored(Context $ctx, array $entry, Variable ...$args): Variable
    {
        if ('closure' === $entry['kind']) {
            return VmClosureCall::invoke($ctx, $entry['closure'], ...$args)->resolveIndirect();
        }
        $cb = new Variable();
        $cb->copyFrom($entry['callable']);

        return VmCallable::invoke($ctx, $cb, ...$args)->resolveIndirect();
    }
}
