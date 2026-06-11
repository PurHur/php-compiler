<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM syslog(3) helpers for openlog()/syslog()/closelog() (#3676 JIT/AOT).
 *
 * Mirrors {@see \PHPCompiler\ext\standard\VmSyslog}. php-src: ext/standard/syslog.c
 */
final class StringSyslog
{
    private const G_OPENED = 'phpc_syslog_opened';

    private const LOG_USER = 8;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_syslog_openlog',
        '__compiler_syslog_write',
        '__compiler_syslog_closelog',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_syslog_write');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureGlobals($context);
        self::ensureLibc($context);

        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');

        $openProbe = $context->module->getNamedFunction('__compiler_syslog_openlog');
        $fnOpen = null !== $openProbe
            ? $openProbe
            : $context->module->addFunction(
                '__compiler_syslog_openlog',
                $context->context->functionType($voidTy, false, $i8p, $i32, $i32)
            );
        self::implementOpenlog($context, $fnOpen);

        $writeProbe = $context->module->getNamedFunction('__compiler_syslog_write');
        $fnWrite = null !== $writeProbe
            ? $writeProbe
            : $context->module->addFunction(
                '__compiler_syslog_write',
                $context->context->functionType($voidTy, false, $i32, $i8p)
            );
        self::implementWrite($context, $fnWrite);

        $closeProbe = $context->module->getNamedFunction('__compiler_syslog_closelog');
        $fnClose = null !== $closeProbe
            ? $closeProbe
            : $context->module->addFunction(
                '__compiler_syslog_closelog',
                $context->context->functionType($voidTy, false)
            );
        self::implementCloselog($context, $fnClose);

        self::registerLinkedRuntime($context);
    }

    private static function implementOpenlog(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sl_open_entry');
        $context->builder->positionAtEnd($entry);

        $ident = $fn->getParam(0);
        $option = $fn->getParam(1);
        $facility = $fn->getParam(2);

        $context->builder->call(
            $context->lookupFunction('openlog'),
            $ident,
            $option,
            $facility
        );
        self::setOpened($context, true);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementWrite(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sl_write_entry');
        $context->builder->positionAtEnd($entry);

        $priority = $fn->getParam(0);
        $message = $fn->getParam(1);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $opened = $context->builder->load(self::openedPtr($context));
        $needsOpen = $context->builder->icmp(Builder::INT_EQ, $opened, $i8->constInt(0, false));

        $openBb = $fn->appendBasicBlock('sl_write_default_open');
        $logBb = $fn->appendBasicBlock('sl_write_log');
        $context->builder->branchIf($needsOpen, $openBb, $logBb);

        $context->builder->positionAtEnd($openBb);
        self::emitDefaultOpenlog($context);
        $context->builder->branch($logBb);

        $context->builder->positionAtEnd($logBb);
        $fmtPtr = self::stackCString($context, '%s');
        $context->builder->call(
            $context->lookupFunction('syslog'),
            $priority,
            $fmtPtr,
            $message
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementCloselog(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sl_close_entry');
        $context->builder->positionAtEnd($entry);

        $context->builder->call($context->lookupFunction('closelog'));
        self::setOpened($context, false);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitDefaultOpenlog(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $identPtr = self::stackCString($context, 'php');
        $context->builder->call(
            $context->lookupFunction('openlog'),
            $identPtr,
            $i32->constInt(0, false),
            $i32->constInt(self::LOG_USER, false)
        );
        self::setOpened($context, true);
    }

    private static function stackCString(Context $context, string $text): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $bytes = $text."\0";
        $len = strlen($bytes);
        $arrTy = $i8->arrayType($len);
        $buf = $context->builder->alloca($arrTy, 1, 'sl_cstr');
        for ($i = 0; $i < $len; ++$i) {
            $context->builder->store(
                $i8->constInt(ord($bytes[$i]), false),
                $context->builder->gep(
                    $buf,
                    $context->getTypeFromString('int32')->constInt(0, false),
                    $context->getTypeFromString('int32')->constInt($i, false)
                )
            );
        }

        return $context->builder->pointerCast($buf, $i8p);
    }

    private static function setOpened(Context $context, bool $opened): void
    {
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store(
            $i8->constInt($opened ? 1 : 0, false),
            self::openedPtr($context)
        );
    }

    private static function openedPtr(Context $context): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $global = $context->module->getNamedGlobal(self::G_OPENED);
        if (null === $global) {
            throw new \LogicException('Missing syslog opened global: '.self::G_OPENED);
        }

        return $context->builder->pointerCast($global, $i8->pointerType(0));
    }

    private static function ensureGlobals(Context $context): void
    {
        if (null === $context->module->getNamedGlobal(self::G_OPENED)) {
            $i8 = $context->getTypeFromString('int8');
            $g = $context->module->addGlobal($i8, self::G_OPENED);
            $g->setInitializer($i8->constInt(0, false));
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');

        self::ensureExternal(
            $context,
            'openlog',
            $context->context->functionType($voidTy, false, $i8p, $i32, $i32)
        );
        self::ensureExternal(
            $context,
            'closelog',
            $context->context->functionType($voidTy, false)
        );
        self::ensureExternal(
            $context,
            'syslog',
            $context->context->functionType($voidTy, false, $i32, $i8p, $i8p)
        );
    }

    private static function ensureExternal(Context $context, string $name, $fnType): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $fnType);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after syslog LLVM link');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
