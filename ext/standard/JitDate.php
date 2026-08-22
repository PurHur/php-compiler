<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DefaultTimezoneCivilRuntime;
use PHPCompiler\JIT\Builtin\DefaultTimezoneRuntime;
use PHPCompiler\JIT\Builtin\ProcessIdentityJit;
use PHPCompiler\JIT\Builtin\StringDateTime;
use PHPCompiler\JIT\Builtin\StringHrtime;
use PHPCompiler\JIT\Builtin\StringMicrotime;
use PHPCompiler\JIT\Builtin\StringStrftime;
use PHPCompiler\JIT\Builtin\StringTime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitDate
{
    public static function time(Context $context): Value
    {
        return StringTime::invoke($context);
    }

    public static function getmypid(Context $context): Value
    {
        return ProcessIdentityJit::getmypid($context);
    }

    public static function getmygrgid(Context $context): Value
    {
        return ProcessIdentityJit::getmygid($context);
    }

    public static function getmyuid(Context $context): Value
    {
        return ProcessIdentityJit::getmyuid($context);
    }

    public static function getmygid(Context $context): Value
    {
        return ProcessIdentityJit::getmygid($context);
    }

    /** date_default_timezone_get() — process default timezone id (#3292 phase 2). */
    public static function defaultTimezoneGet(Context $context): Value
    {
        DefaultTimezoneRuntime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_default_timezone_get'),
            $ptr
        );

        return $ptr;
    }

    /** date_default_timezone_set() — validate and store default timezone (#3292 phase 2). */
    public static function defaultTimezoneSet(Context $context, JITVariable $timezoneId): Value
    {
        DefaultTimezoneRuntime::ensureLinked($context);

        // php_date.stub.php — null DEP+coerce on 8.4 forward profile (#21369, re-#20959)
        $tz = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $timezoneId,
            'date_default_timezone_set',
            0,
            'timezoneId'
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__compiler_default_timezone_set'),
            $tz,
            $ptr
        );

        return $ptr;
    }

    /** timezone_version_get() — tzdata version baked at JIT link from VmDate (#6832, #8032, #29386). */
    public static function timezone_version_get(Context $context): Value
    {
        $version = VmDate::timezone_version_get();
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->load($context->constantStringFromString($version));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return $ptr;
    }

    public static function getmyinode(Context $context): Value
    {
        $block = $context->jitEnclosingBlock;
        if (!$block instanceof Block) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }

        $path = ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE);
        if ('' === $path) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }

        $pathStr = $context->builder->load($context->constantStringFromString($path));

        return JitStat::pathFileInodeBoxed($context, $pathStr);
    }

    public static function getlastmod(Context $context): Value
    {
        $block = $context->jitEnclosingBlock;
        if (!$block instanceof Block) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }

        $path = ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE);
        if ('' === $path) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $i1 = $context->getTypeFromString('int1');
            JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

            return $ptr;
        }

        $pathStr = $context->builder->load($context->constantStringFromString($path));

        return JitStat::pathFileMtimeBoxed($context, $pathStr);
    }

    public static function microtime(Context $context, Value $asFloat): Value
    {
        StringMicrotime::ensureLinked($context); // #32683 — Type always-on microtime ABI dropped
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        $isFloat = $context->builder->icmp(
            Builder::INT_NE,
            $asFloat,
            $context->constantFromBool(false)
        );
        $floatBb = BasicBlockHelper::append($context, 'microtime_float');
        $stringBb = BasicBlockHelper::append($context, 'microtime_string');
        $mergeBb = BasicBlockHelper::append($context, 'microtime_merge');
        $context->builder->branchIf($isFloat, $floatBb, $stringBb);

        $context->builder->positionAtEnd($floatBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            StringMicrotime::invokeFloat($context)
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($stringBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $slotPtr,
            StringMicrotime::invokeString($context)
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $slotPtr;
    }

    public static function hrtime(Context $context, Value $asNumber): Value
    {
        StringHrtime::ensureLinked($context); // Type always-on shells dropped (#32712)

        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        $isNumber = $context->builder->icmp(
            Builder::INT_NE,
            $asNumber,
            $context->constantFromBool(false)
        );
        $numberBb = BasicBlockHelper::append($context, 'hrtime_number');
        $pairBb = BasicBlockHelper::append($context, 'hrtime_pair');
        $mergeBb = BasicBlockHelper::append($context, 'hrtime_merge');
        $context->builder->branchIf($isNumber, $numberBb, $pairBb);

        $context->builder->positionAtEnd($numberBb);
        if (CompilerVersion::supportsHrtimeAsNumberFloat()) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $context->builder->call($context->lookupFunction('__compiler_hrtime_ns'))
            );
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $slotPtr,
                $context->builder->call($context->lookupFunction('__compiler_hrtime_ns'))
            );
        }
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($pairBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $slotPtr,
            $context->builder->call($context->lookupFunction('__compiler_hrtime_pair'))
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $slotPtr;
    }

    public static function formatDate(Context $context, bool $gmt, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $function = $gmt ? 'gmdate' : 'date';
        if ($argc < 1) {
            throw new \ArgumentCountError("{$function}() expects at least 1 argument, 0 given");
        }
        if ($argc > 2) {
            throw new \ArgumentCountError("{$function}() expects at most 2 arguments, {$argc} given");
        }
        $timestamp = $argc >= 2
            ? JitDateTimestampArg::lowerNullable(
                $context,
                $args[1],
                $gmt ? 'gmdate' : 'date',
                2,
                'timestamp',
                self::time($context)
            )
            : self::time($context);

        // Thin AOT: NestedJIT FormatDatetime segfaults; common literals via UTC civil IR
        // (#27091 Y-m-d; #27121 date('Y', strtotime(...)) and peer single-token formats).
        // Free date() T/e/O/P follow runtime set (#33956). date('c'/'r') use civil IR +
        // runtime O/P suffix (#33964) — NestedJIT year-loop helpers abort.
        // gmdate() keeps UTC bake (#33943).
        $fmtLit = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (!$gmt && \is_string($fmtLit)) {
            if ('T' === $fmtLit || 'e' === $fmtLit || 'O' === $fmtLit || 'P' === $fmtLit) {
                return DefaultTimezoneCivilRuntime::emitTimezoneToken($context, $fmtLit, $timestamp);
            }
            if ('c' === $fmtLit) {
                return self::emitDateCWithRuntimeOffset($context, $timestamp);
            }
            if ('r' === $fmtLit || 'D, d M Y H:i:s O' === $fmtLit) {
                return self::emitDateRWithRuntimeOffset($context, $timestamp);
            }
        }
        if (\is_string($fmtLit) && ($gmt || self::defaultTimezoneIsUtc())) {
            $tzLit = self::tryFormatUtcTimezoneLiteral($context, $fmtLit, $timestamp, $gmt);
            if (null !== $tzLit) {
                return $tzLit;
            }
            $civil = self::tryFormatCivilLiteral($context, $fmtLit, $timestamp, null, !$gmt);
            if (null !== $civil) {
                return $civil;
            }
        }

        // Soft-null on 8.4 — Zend deprecate+coerce (#21208, reverts #19651 TypeError)
        $format = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], $function, 0, 'format');
        $gmtI8 = $context->getTypeFromString('int8')->constInt($gmt ? 1 : 0, false);

        // Type always-on format_datetime drop (#33215) — must ensureLinked before lookup.
        StringDateTime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_format_datetime'),
            $format,
            $timestamp,
            $gmtI8
        );
    }

    private static function defaultTimezoneIsUtc(): bool
    {
        $tz = VmDate::defaultTimezoneGet();

        return 'UTC' === $tz || 'Etc/UTC' === $tz || 'Z' === $tz || 'GMT' === $tz;
    }

    /**
     * date('c') — local civil IR + runtime P offset (#33964).
     *
     * Wall clock already comes from {@see JitGetdate::civilPartsPublic} (local);
     * only the hardcoded +00:00 bake was wrong after date_default_timezone_set.
     */
    private static function emitDateCWithRuntimeOffset(Context $context, Value $timestamp): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'date_c_runtime_off');
        $offStr = DefaultTimezoneCivilRuntime::emitTimezoneToken($context, 'P', $timestamp);
        $stringMap = $context->structFieldMap['__string__'];
        $charPtr = $context->getTypeFromString('char*');
        $offChars = $context->builder->pointerCast(
            $context->builder->structGep($offStr, $stringMap['value']),
            $charPtr
        );

        $parts = JitGetdate::civilPartsPublic($context, $timestamp);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $bufSize = 48;
        $buf = $context->builder->alloca($i8, $bufSize, 'c_buf');
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        LibcExtern::ensureSnprintf($context);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%04lld-%02lld-%02lldT%02lld:%02lld:%02lld%s'),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $sizeT->constInt($bufSize, false),
            $fmt,
            $parts['year'],
            $parts['month'],
            $parts['day'],
            $parts['hour'],
            $parts['minute'],
            $parts['second'],
            $offChars
        );
        $len = $context->builder->sext($written, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
    }

    /**
     * date('r') — local civil IR + runtime O offset (#33964).
     *
     * php-src: ext/date/php_date.c — php_format_date token 'r'
     */
    private static function emitDateRWithRuntimeOffset(Context $context, Value $timestamp): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'date_r_runtime_off');
        $offStr = DefaultTimezoneCivilRuntime::emitTimezoneToken($context, 'O', $timestamp);
        $stringMap = $context->structFieldMap['__string__'];
        $charPtr = $context->getTypeFromString('char*');
        $offChars = $context->builder->pointerCast(
            $context->builder->structGep($offStr, $stringMap['value']),
            $charPtr
        );

        $parts = JitGetdate::civilPartsPublic($context, $timestamp);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');

        $weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $dAbbr = $context->builder->pointerCast($context->constantFromString('Sat'), $charPtr);
        foreach ($weekdays as $i => $name) {
            $is = $context->builder->icmp(Builder::INT_EQ, $parts['wday'], $i64->constInt($i, false));
            $str = $context->builder->pointerCast($context->constantFromString($name), $charPtr);
            $dAbbr = $context->builder->select($is, $str, $dAbbr);
        }
        $mAbbr = $context->builder->pointerCast($context->constantFromString('Jan'), $charPtr);
        foreach ($months as $i => $name) {
            $is = $context->builder->icmp(
                Builder::INT_EQ,
                $parts['month'],
                $i64->constInt($i + 1, false)
            );
            $str = $context->builder->pointerCast($context->constantFromString($name), $charPtr);
            $mAbbr = $context->builder->select($is, $str, $mAbbr);
        }

        $bufSize = 48;
        $buf = $context->builder->alloca($i8, $bufSize, 'r_buf');
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        LibcExtern::ensureSnprintf($context);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%s, %02lld %s %04lld %02lld:%02lld:%02lld %s'),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $sizeT->constInt($bufSize, false),
            $fmt,
            $dAbbr,
            $parts['day'],
            $mAbbr,
            $parts['year'],
            $parts['hour'],
            $parts['minute'],
            $parts['second'],
            $offChars
        );
        $len = $context->builder->sext($written, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
    }

    /**
     * Compile-time date()/gmdate()/DateTime::format() literals via civil IR + snprintf
     * (#27091, #27121, #27192).
     *
     * Returns null when the format needs the NestedJIT FormatDatetime path.
     * Shared with DateTimeFormatJitHelper — NestedJIT formatStateArgv civil digests
     * segfault under PHP_COMPILER_PROFILE=8.4 thin AOT (#27192).
     */
    public static function tryFormatCivilLiteral(
        Context $context,
        string $fmtLit,
        Value $timestamp,
        ?Value $microsecond = null,
        bool $local = true
    ): ?Value {
        // 'U' is the unix timestamp itself — no civil breakdown (#27121).
        if ('U' === $fmtLit) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'date_u_civil');
            $i8 = $context->getTypeFromString('int8');
            $i64 = $context->getTypeFromString('int64');
            $sizeT = $context->getTypeFromString('size_t');
            $charPtr = $context->getTypeFromString('char*');
            $buf = $context->builder->alloca($i8, 24, 'u_buf');
            $bufChar = $context->builder->pointerCast($buf, $charPtr);
            // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
            LibcExtern::ensureSnprintf($context);
            $fmt = $context->builder->pointerCast($context->constantFromString('%lld'), $charPtr);
            $written = $context->builder->call(
                $context->lookupFunction('snprintf'),
                $bufChar,
                $sizeT->constInt(24, false),
                $fmt,
                $timestamp
            );
            $len = $context->builder->sext($written, $i64);

            return $context->builder->call(
                $context->lookupFunction('__string__init'),
                $len,
                $bufChar
            );
        }

        // Bare `u` and `H:i:s.u` go through $specs below (#33930). A dedicated early
        // snprintf for `u` (added in #33927) SIGSEGV'd under thin AOT; `H:i:s.u` was
        // never registered and fell through to NestedJIT DateTimeFormatRuntime (SIGABRT).

        /** @var array<string, array{0:string,1:list<string>,2:int}> $specs */
        $specs = [
            'Y' => ['%04lld', ['year'], 8],
            'y' => ['%02lld', ['year2'], 4],
            'm' => ['%02lld', ['month'], 4],
            'd' => ['%02lld', ['day'], 4],
            'H' => ['%02lld', ['hour'], 4],
            'i' => ['%02lld', ['minute'], 4],
            's' => ['%02lld', ['second'], 4],
            // Microseconds — same snprintf family as Y-m-d H:i:s.u (#33930 / re-#33922).
            'u' => ['%06lld', ['micro'], 16],
            'Y-m-d' => ['%04lld-%02lld-%02lld', ['year', 'month', 'day'], 16],
            'Ymd' => ['%04lld%02lld%02lld', ['year', 'month', 'day'], 12],
            'H:i:s' => ['%02lld:%02lld:%02lld', ['hour', 'minute', 'second'], 12],
            // Composite literals — NestedJIT FormatDatetime segfaults (#27157).
            'Y-m-d H:i' => [
                '%04lld-%02lld-%02lld %02lld:%02lld',
                ['year', 'month', 'day', 'hour', 'minute'],
                24,
            ],
            'Y-m-d H:i:s' => [
                '%04lld-%02lld-%02lld %02lld:%02lld:%02lld',
                ['year', 'month', 'day', 'hour', 'minute', 'second'],
                32,
            ],
            // Fractional wall clock — bake micro so format() skips NestedJIT (#33922 / #33930).
            'H:i:s.u' => [
                '%02lld:%02lld:%02lld.%06lld',
                ['hour', 'minute', 'second', 'micro'],
                24,
            ],
            'Y-m-d H:i:s.u' => [
                '%04lld-%02lld-%02lld %02lld:%02lld:%02lld.%06lld',
                ['year', 'month', 'day', 'hour', 'minute', 'second', 'micro'],
                40,
            ],
            // gmdate('c') / date('c') under UTC — fixed +00:00 (#27157).
            'c' => [
                '%04lld-%02lld-%02lldT%02lld:%02lld:%02lld+00:00',
                ['year', 'month', 'day', 'hour', 'minute', 'second'],
                40,
            ],
        ];
        if (!isset($specs[$fmtLit])) {
            return null;
        }
        [$printfFmt, $keys, $bufSize] = $specs[$fmtLit];

        BasicBlockHelper::ensureOpenInsertBlock($context, 'date_civil_lit');
        $parts = JitGetdate::civilPartsPublic($context, $timestamp, $local);
        // Two-digit year for 'y' — Zend date('y') (#27121 peer).
        $i64 = $context->getTypeFromString('int64');
        $parts['year2'] = $context->builder->signedRem($parts['year'], $i64->constInt(100, false));
        $parts['micro'] = $microsecond ?? $i64->constInt(0, false);
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $buf = $context->builder->alloca($i8, $bufSize, 'civil_buf');
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $fmt = $context->builder->pointerCast($context->constantFromString($printfFmt), $charPtr);
        $callArgs = [$bufChar, $sizeT->constInt($bufSize, false), $fmt];
        foreach ($keys as $key) {
            $callArgs[] = $parts[$key];
        }
        $written = $context->builder->call($context->lookupFunction('snprintf'), ...$callArgs);
        $len = $context->builder->sext($written, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
    }

    /**
     * Free date()/gmdate() timezone-token literals under UTC/GMT (#33943).
     *
     * Kept out of {@see tryFormatCivilLiteral} so DateTime::format named zones stay on
     * the #33939 fold / NestedJIT path (UTC bake would miscompile America/New_York).
     */
    private static function tryFormatUtcTimezoneLiteral(
        Context $context,
        string $fmtLit,
        Value $timestamp,
        bool $gmt
    ): ?Value {
        // Zend: gmdate('T') → GMT; date('T') under UTC → UTC; 'e' is UTC for both.
        $utcTzConsts = [
            'T' => $gmt ? 'GMT' : 'UTC',
            'e' => 'UTC',
            'O' => '+0000',
            'P' => '+00:00',
        ];
        if (isset($utcTzConsts[$fmtLit])) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'date_utc_tz_lit');

            return $context->builder->load($context->constantStringFromString($utcTzConsts[$fmtLit]));
        }
        if ('r' === $fmtLit || 'D, d M Y H:i:s O' === $fmtLit) {
            return self::tryFormatCivilRfc2822Utc($context, $timestamp, !$gmt);
        }

        return null;
    }

    /**
     * UTC/local civil IR for date('r') / DATE_RFC2822 — NestedJIT FormatDatetime SIGSEGV (#33943).
     *
     * php-src: ext/date/php_date.c — php_format_date token 'r' → "D, d M Y H:i:s O"
     * {@see $local} false for gmdate() after a named default zone (#33964).
     */
    private static function tryFormatCivilRfc2822Utc(Context $context, Value $timestamp, bool $local = true): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'date_r_civil');
        $parts = JitGetdate::civilPartsPublic($context, $timestamp, $local);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');

        // PHP getdate wday: 0=Sunday … 6=Saturday; 'D' / 'M' abbreviations.
        $weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $dAbbr = $context->builder->pointerCast($context->constantFromString('Sat'), $charPtr);
        foreach ($weekdays as $i => $name) {
            $is = $context->builder->icmp(Builder::INT_EQ, $parts['wday'], $i64->constInt($i, false));
            $str = $context->builder->pointerCast($context->constantFromString($name), $charPtr);
            $dAbbr = $context->builder->select($is, $str, $dAbbr);
        }
        $mAbbr = $context->builder->pointerCast($context->constantFromString('Jan'), $charPtr);
        foreach ($months as $i => $name) {
            $is = $context->builder->icmp(
                Builder::INT_EQ,
                $parts['month'],
                $i64->constInt($i + 1, false)
            );
            $str = $context->builder->pointerCast($context->constantFromString($name), $charPtr);
            $mAbbr = $context->builder->select($is, $str, $mAbbr);
        }

        $bufSize = 48;
        $buf = $context->builder->alloca($i8, $bufSize, 'r_buf');
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        LibcExtern::ensureSnprintf($context);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%s, %02lld %s %04lld %02lld:%02lld:%02lld +0000'),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $sizeT->constInt($bufSize, false),
            $fmt,
            $dAbbr,
            $parts['day'],
            $mAbbr,
            $parts['year'],
            $parts['hour'],
            $parts['minute'],
            $parts['second']
        );
        $len = $context->builder->sext($written, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
    }

    public static function formatStrftime(Context $context, bool $gmt, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $function = $gmt ? 'gmstrftime' : 'strftime';
        if ($argc < 1) {
            throw new \ArgumentCountError("{$function}() expects at least 1 argument, 0 given");
        }
        if ($argc > 2) {
            throw new \ArgumentCountError("{$function}() expects at most 2 arguments, {$argc} given");
        }
        VmEngineBuiltinDeprecation::emitJitFunction($context, $function);
        // Soft-null $format → DEP + false (Zend 8.4.23; #21582, reverts #20227 TypeError).
        // Keep false (not '') for #18945 — do not lower through Z_PARAM_STR → php_strftime("").
        // Compile-time null folds to native bool like checkdate AOT (#21594) — value-box false
        // segfaults under AOT assign/var_export.
        // strict_types still TypeError via lowerZparamStr below.
        if (
            (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
            && !$context->callerStrictTypes
        ) {
            JitStringBuiltinArg::emitNullStringParamDeprecation($context, $function, 0, 'format');

            return $context->constantFromBool(false);
        }
        $format = JitStringBuiltinArg::lowerZparamStr($context, $args[0], $function, 0, 'format');
        $timestamp = $argc >= 2
            ? JitDateTimestampArg::lowerNullable(
                $context,
                $args[1],
                $gmt ? 'gmstrftime' : 'strftime',
                2,
                'timestamp',
                self::time($context)
            )
            : self::time($context);
        $gmtI8 = $context->getTypeFromString('int8')->constInt($gmt ? 1 : 0, false);

        // Type always-on strftime drop (#33222) — must ensureLinked before lookup.
        StringStrftime::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_strftime'),
            $format,
            $timestamp,
            $gmtI8
        );
    }

}
