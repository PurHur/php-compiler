<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM;

/**
 * CSV delimiter/enclosure/escape validation (php-src ext/standard/file.c, issues #4530, #1193).
 *
 * PHP 8.4+ omitted-$escape E_DEPRECATED for str_getcsv/fgetcsv/fputcsv (#21174, #21179).
 */
final class VmCsvArg
{
    public static function validateFputcsvOptions(
        string $separator,
        string $enclosure,
        string $escape,
    ): void {
        self::requireSingleChar('fputcsv', $separator, 3, 'separator');
        self::requireSingleChar('fputcsv', $enclosure, 4, 'enclosure');
        self::requireEmptyOrSingleChar('fputcsv', $escape, 5, 'escape');
    }

    public static function validateFgetcsvOptions(
        string $separator,
        string $enclosure,
        string $escape,
    ): void {
        self::requireSingleChar('fgetcsv', $separator, 3, 'separator');
        self::requireSingleChar('fgetcsv', $enclosure, 4, 'enclosure');
        self::requireEmptyOrSingleChar('fgetcsv', $escape, 5, 'escape');
    }

    /**
     * php-src file.c — PHP 8.4 deprecates calling CSV builtins without an explicit $escape
     * (default will change). Gated on language profile ≥ 8.4 (explicit PROFILE=8.4 / stable 8.4.0+);
     * 8.4.0-dev reference profile without PROFILE stays silent like Zend 8.2 CI.
     */
    public static function shouldDeprecateOmittedEscape(): bool
    {
        return version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=');
    }

    public static function omittedEscapeMessage(string $function): string
    {
        return $function.'(): the $escape parameter must be provided as its default value will change';
    }

    public static function emitOmittedEscapeDeprecation(?Frame $frame, string $function): void
    {
        if (!self::shouldDeprecateOmittedEscape()) {
            return;
        }
        $vm = VM::running();
        if (null === $vm) {
            return;
        }
        if (null === $frame) {
            $frame = $vm->builtinHandlerFrame();
            if (null === $frame) {
                $frames = $vm->context->runStackFrames();
                $frame = [] !== $frames ? $frames[0] : null;
            }
        }
        $vm->context->errors->internalDeprecated(
            self::omittedEscapeMessage($function),
            $vm->context,
            $frame
        );
    }

    public static function emitJitOmittedEscapeDeprecation(Context $context, string $function): void
    {
        if (!self::shouldDeprecateOmittedEscape()) {
            return;
        }
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        JitBuiltinWarning::emitDeprecated($context, self::omittedEscapeMessage($function));
    }

    public static function requireSingleChar(string $function, string $value, int $argNum, string $paramName): void
    {
        if (1 !== \strlen($value)) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($%s) must be a single character',
                $function,
                $argNum,
                $paramName
            ));
        }
    }

    public static function requireEmptyOrSingleChar(string $function, string $value, int $argNum, string $paramName): void
    {
        if (\strlen($value) > 1) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($%s) must be empty or a single character',
                $function,
                $argNum,
                $paramName
            ));
        }
    }
}
