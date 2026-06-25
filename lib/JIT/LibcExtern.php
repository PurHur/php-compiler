<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Canonical C library extern declarations for AOT/MCJIT modules.
 *
 * Registers malloc/free and string/memory helpers with int8* pointer types so
 * per-builtin ensureLibc() helpers cannot introduce conflicting void* signatures.
 */
final class LibcExtern
{
    public static function register(Context $context): void
    {
        $ctx = $context->context;
        $void = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $dbl = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $i32p = $i32->pointerType(0);

        /** @var array<string, array{0: mixed, 1: bool, 2: list<mixed>}> $specs */
        $specs = [
            'malloc' => [$i8p, false, [$sizeT]],
            'calloc' => [$i8p, false, [$sizeT, $sizeT]],
            'realloc' => [$i8p, false, [$i8p, $sizeT]],
            'free' => [$void, false, [$i8p]],
            'memcpy' => [$i8p, false, [$i8p, $i8p, $sizeT]],
            'memmove' => [$i8p, false, [$i8p, $i8p, $sizeT]],
            'memset' => [$i8p, false, [$i8p, $i32, $sizeT]],
            'memcmp' => [$i32, false, [$i8p, $i8p, $sizeT]],
            'memchr' => [$i8p, false, [$i8p, $i32, $sizeT]],
            'strlen' => [$sizeT, false, [$i8p]],
            'strcmp' => [$i32, false, [$i8p, $i8p]],
            'strncmp' => [$i32, false, [$i8p, $i8p, $sizeT]],
            'strcasecmp' => [$i32, false, [$i8p, $i8p]],
            'strncasecmp' => [$i32, false, [$i8p, $i8p, $sizeT]],
            'strchr' => [$i8p, false, [$i8p, $i32]],
            'strrchr' => [$i8p, false, [$i8p, $i32]],
            'strstr' => [$i8p, false, [$i8p, $i8p]],
            'strcasestr' => [$i8p, false, [$i8p, $i8p]],
            'strpbrk' => [$i8p, false, [$i8p, $i8p]],
            'strncpy' => [$i8p, false, [$i8p, $i8p, $sizeT]],
            'strtol' => [$i64, false, [$i8p, $i8pp, $i32]],
            'strtod' => [$dbl, false, [$i8p, $i8pp]],
            'strdup' => [$i8p, false, [$i8p]],
            'strtok_r' => [$i8p, false, [$i8p, $i8p, $i8pp]],
            'fopen' => [$i8p, false, [$i8p, $i8p]],
            'fread' => [$sizeT, false, [$i8p, $sizeT, $sizeT, $i8p]],
            'fwrite' => [$sizeT, false, [$i8p, $sizeT, $sizeT, $i8p]],
            'fclose' => [$i32, false, [$i8p]],
            'fflush' => [$i32, false, [$i8p]],
            'ferror' => [$i32, false, [$i8p]],
            'fgets' => [$i8p, false, [$i8p, $i32, $i8p]],
            'open' => [$i32, false, [$i8p, $i32, $i32]],
            'close' => [$i32, false, [$i32]],
            'read' => [$i64, false, [$i32, $i8p, $i64]],
            'write' => [$i64, false, [$i32, $i8p, $i64]],
            'stat' => [$i32, false, [$i8p, $i8p]],
            'access' => [$i32, false, [$i8p, $i32]],
            'lstat' => [$i32, false, [$i8p, $i8p]],
            'chmod' => [$i32, false, [$i8p, $i32]],
            'utime' => [$i32, false, [$i8p, $i8p]],
            'mkstemp' => [$i32, false, [$i8p]],
            'chown' => [$i32, false, [$i8p, $i32, $i32]],
            'fchownat' => [$i32, false, [$i32, $i8p, $i32, $i32, $i32]],
            'getgrnam' => [$i8p, false, [$i8p]],
            'getpwnam' => [$i8p, false, [$i8p]],
            'mkdir' => [$i32, false, [$i8p, $i32]],
            'remove' => [$i32, false, [$i8p]],
            'rename' => [$i32, false, [$i8p, $i8p]],
            'getenv' => [$i8p, false, [$i8p]],
            'realpath' => [$i8p, false, [$i8p, $i8p]],
            'time' => [$i64, false, [$i8p]],
            'printf' => [$i32, true, [$i8p]],
            'snprintf' => [$i32, true, [$i8p, $sizeT, $i8p]],
            'sscanf' => [$i32, true, [$i8p, $i8p]],
            'popen' => [$i8p, false, [$i8p, $i8p]],
            'pclose' => [$i32, false, [$i8p]],
            'pipe' => [$i32, false, [$i32p]],
            'fork' => [$i32, false, []],
            'dup2' => [$i32, false, [$i32, $i32]],
            'waitpid' => [$i32, false, [$i32, $i32p, $i32]],
            '__phpc_resolve_stream' => [$i8p, false, [$i64]],
            'fileno' => [$i32, false, [$i8p]],
            'flock' => [$i32, false, [$i32, $i32]],
        ];

        foreach ($specs as $name => [$ret, $vararg, $params]) {
            self::ensure($context, $name, $ctx->functionType($ret, $vararg, ...$params));
        }
    }

    private static function ensure(Context $context, string $name, $fnType): void
    {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        try {
            $context->lookupFunction($name);

            return;
        } catch (\Throwable) {
        }
        $fn = $context->module->addFunction($name, $fnType);
        $context->registerFunction($name, $fn);
    }
}
