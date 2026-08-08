<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\BackedEnum;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\ResponseContext;

/**
 * VM helpers for http_response_code() (#7322, #9306; ext/standard/head.c).
 */
final class VmHttpResponse
{
    public static function resolveCodeArg(Variable $var, string $fn): int
    {
        $resolved = $var->resolveIndirect();
        $fromEnum = self::tryResponseCodeInt($resolved);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($resolved)) {
            // php-src has no ResponseCode; any enum → TypeError like Zend (#28931, re-#7322).
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($response_code) must be of type int, %s given',
                $fn,
                EnumCaseSupport::typeNameForVariable($resolved)
            ));
        }

        return VmMath::parseIntBuiltinArg($var, $fn, 1, 'response_code');
    }

    public static function tryResponseCodeInt(Variable $var): ?int
    {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return null;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isResponseCodeEnum($enumClass->name)) {
            return null;
        }
        $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
        if (null === $entry || null === $entry->backingValue) {
            throw new \LogicException('ResponseCode case missing backing value');
        }

        return $entry->backingValue->resolveIndirect()->toInt();
    }

    /**
     * @param int|false $value
     */
    public static function assignReadResult(Variable $dest, $value, ?Context $ctx): void
    {
        if (false === $value) {
            $dest->bool(false);

            return;
        }
        $dest->int((int) $value);
    }

    /**
     * @param true|int|false $value
     */
    public static function assignWriteResult(Variable $dest, $value, ?Context $ctx): void
    {
        if (false === $value) {
            $dest->bool(false);

            return;
        }
        if (true === $value) {
            $dest->bool(true);

            return;
        }
        $dest->int((int) $value);
    }

    public static function enumCaseForStatusCode(?Context $ctx, int $code): ?Variable
    {
        if (null === $ctx || !isset($ctx->classes['responsecode'])) {
            return null;
        }
        $enum = $ctx->classes['responsecode'];
        $needle = new Variable(Variable::TYPE_INTEGER);
        $needle->int($code);
        $match = BackedEnum::tryCaseForValue($enum, $needle);
        if (null === $match) {
            return null;
        }
        $canonical = BackedEnum::canonicalCaseVariable($enum, $match->caseName);
        if (null !== $canonical) {
            return $canonical;
        }

        return EnumCaseSupport::createCase($enum, $match->caseName, $match->backingValue);
    }

    public static function readHttpResponseCode(?Context $ctx)
    {
        return ResponseContext::readHttpResponseCode();
    }

    public static function writeHttpResponseCode(int $code)
    {
        return ResponseContext::writeHttpResponseCode($code);
    }

    private static function isResponseCodeEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'ResponseCode');
    }
}
