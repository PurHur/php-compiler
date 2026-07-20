<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** Shared return helpers for Reflection*::getDeprecatedMessage/Version (#6917). */
final class ReflectionDeprecatedReturn
{
    public static function classMessage(Frame $frame, Variable $receiver): void
    {
        $entry = self::classEntry($frame, $receiver);

        self::returnNullableString($frame, $entry->classDeprecated?->message);
    }

    public static function classVersion(Frame $frame, Variable $receiver): void
    {
        $entry = self::classEntry($frame, $receiver);

        self::returnNullableString($frame, $entry->classDeprecated?->since);
    }

    public static function methodMessage(Frame $frame, Variable $receiver): void
    {
        $meta = self::methodMetadata($frame, $receiver);

        self::returnNullableString($frame, $meta?->message);
    }

    public static function methodVersion(Frame $frame, Variable $receiver): void
    {
        $meta = self::methodMetadata($frame, $receiver);

        self::returnNullableString($frame, $meta?->since);
    }

    public static function classConstantMessage(Frame $frame, Variable $receiver): void
    {
        $meta = self::classConstantMetadata($frame, $receiver);

        self::returnNullableString($frame, $meta?->message);
    }

    public static function classConstantVersion(Frame $frame, Variable $receiver): void
    {
        $meta = self::classConstantMetadata($frame, $receiver);

        self::returnNullableString($frame, $meta?->since);
    }

    /** ReflectionConstant (global) deprecation metadata (#21255). */
    public static function globalConstantMetadata(Frame $frame, Variable $receiver): ?DeprecatedMetadata
    {
        $ctx = VmReflection::requireContext($frame);
        $obj = ReflectionSupport::requireReflectionConstant($frame, $receiver);
        $constant = ReflectionSupport::constantNameFromReflection($obj);

        return $ctx->globalConstDeprecated[strtolower($constant)] ?? null;
    }

    public static function globalConstantMessage(Frame $frame, Variable $receiver): void
    {
        $meta = self::globalConstantMetadata($frame, $receiver);

        self::returnNullableString($frame, $meta?->message);
    }

    public static function globalConstantVersion(Frame $frame, Variable $receiver): void
    {
        $meta = self::globalConstantMetadata($frame, $receiver);

        self::returnNullableString($frame, $meta?->since);
    }

    private static function classEntry(Frame $frame, Variable $receiver): \PHPCompiler\VM\ClassEntry
    {
        $receiver = ReflectionSupport::requireReflectionClass($frame, $receiver);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }

        return $entry;
    }

    private static function methodMetadata(Frame $frame, Variable $receiver): ?DeprecatedMetadata
    {
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $receiver);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $methodName = ReflectionSupport::methodNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionMethod refers to unknown class in this compiler build');
        }
        $methodLc = strtolower($methodName);

        return $entry->methodDeprecated[$methodLc] ?? null;
    }

    private static function classConstantMetadata(Frame $frame, Variable $receiver): ?DeprecatedMetadata
    {
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionClassConstant($frame, $receiver);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClassConstant refers to unknown class in this compiler build');
        }
        $constant = ReflectionSupport::constantNameFromReflection($receiver);
        $key = VmReflection::findClassConstantKey($entry, $constant, $ctx);
        if (null === $key) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::constantNotFoundMessage($className, $constant)
            );
        }

        return $entry->constDeprecated[$key] ?? null;
    }

    private static function returnNullableString(Frame $frame, ?string $value): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $value) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($value);
        }
    }
}
