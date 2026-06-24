<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * stream_notification_callback() global notifier + dispatch (ext/standard/streams.c, #6055).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_notification_callback),
 * user_space_stream_notifier(), php_stream_notification_* in main/streams/streams.c
 */
final class VmStreamNotification
{
    public const NOTIFY_RESOLVE = 1;
    public const NOTIFY_CONNECT = 2;
    public const NOTIFY_AUTH_REQUIRED = 3;
    public const NOTIFY_MIME_TYPE_IS = 4;
    public const NOTIFY_FILE_SIZE_IS = 5;
    public const NOTIFY_REDIRECTED = 6;
    public const NOTIFY_PROGRESS = 7;
    public const NOTIFY_COMPLETED = 8;
    public const NOTIFY_FAILURE = 9;
    public const NOTIFY_AUTH_RESULT = 10;

    public const SEVERITY_INFO = 0;
    public const SEVERITY_WARN = 1;
    public const SEVERITY_ERR = 2;

    private const PARAM_NOTIFICATION = 'notification';

    /** @return array<string, int> */
    public static function constants(): array
    {
        return [
            'STREAM_NOTIFY_RESOLVE' => self::NOTIFY_RESOLVE,
            'STREAM_NOTIFY_CONNECT' => self::NOTIFY_CONNECT,
            'STREAM_NOTIFY_AUTH_REQUIRED' => self::NOTIFY_AUTH_REQUIRED,
            'STREAM_NOTIFY_MIME_TYPE_IS' => self::NOTIFY_MIME_TYPE_IS,
            'STREAM_NOTIFY_FILE_SIZE_IS' => self::NOTIFY_FILE_SIZE_IS,
            'STREAM_NOTIFY_REDIRECTED' => self::NOTIFY_REDIRECTED,
            'STREAM_NOTIFY_PROGRESS' => self::NOTIFY_PROGRESS,
            'STREAM_NOTIFY_COMPLETED' => self::NOTIFY_COMPLETED,
            'STREAM_NOTIFY_FAILURE' => self::NOTIFY_FAILURE,
            'STREAM_NOTIFY_AUTH_RESULT' => self::NOTIFY_AUTH_RESULT,
            'STREAM_NOTIFY_SEVERITY_INFO' => self::SEVERITY_INFO,
            'STREAM_NOTIFY_SEVERITY_WARN' => self::SEVERITY_WARN,
            'STREAM_NOTIFY_SEVERITY_ERR' => self::SEVERITY_ERR,
        ];
    }

    public static function setGlobal(Variable $callback): Variable
    {
        return StreamNotificationJitHelper::setGlobal($callback);
    }

    public static function globalCallback(): ?Variable
    {
        return StreamNotificationJitHelper::globalCallback();
    }

    /**
     * Resolve notifier: per-context params.notification overrides global callback.
     */
    public static function resolveForContext(?Variable $contextVar): ?Variable
    {
        if (null !== $contextVar) {
            $fromContext = self::callbackFromContextParams($contextVar);
            if (null !== $fromContext) {
                return $fromContext;
            }
        }

        return self::globalCallback();
    }

    public static function validateContextNotificationParam(Variable $callback, string $functionName): void
    {
        $resolved = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return;
        }
        if (!self::isInvokableCallback($callback)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($params) must be an array with valid callbacks as values, no array or string given',
                $functionName
            ));
        }
    }

    public static function requireValidCallback(Variable $callback): void
    {
        $resolved = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return;
        }
        if (!self::isInvokableCallback($callback)) {
            throw new \TypeError(
                'stream_notification_callback(): Argument #1 ($callback) must be a valid callback'
            );
        }
    }

    public static function dispatch(
        Context $ctx,
        ?Variable $contextVar,
        int $notificationCode,
        int $severity,
        ?string $message,
        int $messageCode,
        int $bytesTransferred,
        int $bytesMax
    ): void {
        $callback = self::resolveForContext($contextVar);
        if (null === $callback) {
            return;
        }
        self::invoke(
            $ctx,
            $callback,
            self::intVar($notificationCode),
            self::intVar($severity),
            self::messageVar($message),
            self::intVar($messageCode),
            self::intVar($bytesTransferred),
            self::intVar($bytesMax)
        );
    }

    private static function callbackFromContextParams(Variable $contextVar): ?Variable
    {
        if (!VmStreamContext::isRepresentation($contextVar)) {
            return null;
        }
        $paramsSlot = $contextVar->resolveIndirect()->toArray()->find(VmStreamContext::PARAMS_MARKER_KEY);
        if (null === $paramsSlot) {
            return null;
        }
        $params = $paramsSlot->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $params->type) {
            return null;
        }
        $notification = $params->toArray()->find(self::PARAM_NOTIFICATION);
        if (null === $notification) {
            return null;
        }
        $resolved = $notification->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved;
    }

    private static function isInvokableCallback(Variable $callback): bool
    {
        $resolved = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return true;
        }
        if (EnumCaseSupport::isEnumCaseVariable($callback)) {
            return false;
        }
        if (VmClosureCall::isClosure($resolved)) {
            return true;
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            return true;
        }
        if (Variable::TYPE_ARRAY === $resolved->type) {
            return true;
        }
        if (Variable::TYPE_OBJECT === $resolved->type) {
            return null === $resolved->toObject()->closureState;
        }

        return false;
    }

    private static function invoke(Context $ctx, Variable $callback, Variable ...$args): void
    {
        try {
            VmCallable::invoke($ctx, $callback, ...$args);
        } catch (\Throwable) {
            // php-src: user notifier failures become warnings; copy continues.
        }
    }

    private static function intVar(int $value): Variable
    {
        $var = new Variable();
        $var->int($value);

        return $var;
    }

    private static function messageVar(?string $message): Variable
    {
        $var = new Variable();
        if (null === $message) {
            $var->null();
        } else {
            $var->string($message);
        }

        return $var;
    }
}
