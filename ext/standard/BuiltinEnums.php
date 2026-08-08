<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\Variable;

/**
 * Register ext/standard builtin enums (php-src Zend/zend_enum.def; issue #7222).
 */
final class BuiltinEnums
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        if (CompilerVersion::supportsBuiltinStubEnums()) {
            self::registerPropertyHookType($ctx);
            // ExitStatus retired — php-src never ships it (#28500, re-#28200 / #7294)
            // StringTrimMode retired — php-src never ships it (#28202, re-#7283); trim arity 2 (#28230)
            self::registerPadType($ctx);
            // MemoryUsage retired — php-src never ships it (#28411, re-#7247)
            // ConnectionStatus / ResponseCode / RequestMethod retired — php-src never ships them (#28931)
            // Sorting / SortDirection retired — php-src never ships them (#28930, re-#12362 / #7229 / #7261)
            // ParseUrl retired — php-src never ships it (#28536, re-#7260)
            self::registerInfoView($ctx);
        }
        if (CompilerVersion::supportsClockGettime()) {
            self::registerClockInterface($ctx);
        }
        if (CompilerVersion::supportsRoundingModeEnum()) {
            self::registerRoundingMode($ctx);
        }
        if (CompilerVersion::supportsArrayPadTypeEnum()) {
            self::registerArrayPadType($ctx);
        }
        if (CompilerVersion::supportsStreamErrorApi()) {
            self::registerStreamErrorCode($ctx);
            self::registerStreamErrorMode($ctx);
            self::registerStreamErrorStore($ctx);
        }
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /**
     * PHP 8.6 StreamErrorCode unit enum (main/streams/stream_errors.stub.php; #21020).
     */
    private static function registerStreamErrorCode(Context $ctx): void
    {
        if (isset($ctx->classes['streamerrorcode'])) {
            return;
        }

        $entry = new ClassEntry('StreamErrorCode');
        $entry->isEnum = true;

        foreach ([
            'None', 'Generic',
            'ReadFailed', 'WriteFailed', 'SeekFailed', 'SeekNotSupported', 'FlushFailed', 'TruncateFailed',
            'ConnectFailed', 'BindFailed', 'ListenFailed', 'AcceptFailed', 'NotWritable', 'NotReadable',
            'Disabled', 'NotFound', 'PermissionDenied', 'AlreadyExists', 'InvalidPath', 'PathTooLong',
            'OpenFailed', 'CreateFailed', 'DupFailed', 'UnlinkFailed', 'RenameFailed', 'MkdirFailed',
            'RmdirFailed', 'StatFailed', 'MetaFailed', 'ChmodFailed', 'ChownFailed', 'CopyFailed',
            'TouchFailed', 'InvalidMode', 'InvalidMeta', 'ModeNotSupported', 'Readonly', 'RecursionDetected',
            'NotImplemented', 'NoOpener', 'PersistentNotSupported', 'WrapperNotFound', 'WrapperDisabled',
            'ProtocolUnsupported', 'WrapperRegistrationFailed', 'WrapperUnregistrationFailed',
            'WrapperRestorationFailed',
            'FilterNotFound', 'FilterFailed',
            'CastFailed', 'CastNotSupported', 'MakeSeekableFailed', 'BufferedDataLost',
            'NetworkSendFailed', 'NetworkRecvFailed', 'SslNotSupported', 'ResumptionFailed',
            'SocketPathTooLong', 'OobNotSupported', 'ProtocolError', 'InvalidUrl', 'InvalidResponse',
            'InvalidHeader', 'InvalidParam', 'RedirectLimit', 'AuthFailed', 'TimeOut',
            'ArchivingFailed', 'EncodingFailed', 'DecodingFailed', 'InvalidFormat',
            'AllocationFailed', 'TemporaryFileFailed',
            'LockFailed', 'LockNotSupported',
            'UserspaceNotImplemented', 'UserspaceInvalidReturn', 'UserspaceCallFailed',
        ] as $name) {
            self::registerPureEnumCase($entry, $name);
        }

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $ctx->classes['streamerrorcode'] = $entry;
        $ctx->enums['streamerrorcode'] = true;
    }

    /**
     * PHP 8.6 StreamErrorMode unit enum (main/streams/stream_errors.stub.php; #21020).
     */
    private static function registerStreamErrorMode(Context $ctx): void
    {
        if (isset($ctx->classes['streamerrormode'])) {
            return;
        }

        $entry = new ClassEntry('StreamErrorMode');
        $entry->isEnum = true;
        foreach (['Error', 'Exception', 'Silent'] as $name) {
            self::registerPureEnumCase($entry, $name);
        }
        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);
        $ctx->classes['streamerrormode'] = $entry;
        $ctx->enums['streamerrormode'] = true;
    }

    /**
     * PHP 8.6 StreamErrorStore unit enum (main/streams/stream_errors.stub.php; #21020).
     */
    private static function registerStreamErrorStore(Context $ctx): void
    {
        if (isset($ctx->classes['streamerrorstore'])) {
            return;
        }

        $entry = new ClassEntry('StreamErrorStore');
        $entry->isEnum = true;
        foreach (['Auto', 'None', 'NonTerminating', 'Terminating', 'All'] as $name) {
            self::registerPureEnumCase($entry, $name);
        }
        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);
        $ctx->classes['streamerrorstore'] = $entry;
        $ctx->enums['streamerrorstore'] = true;
    }

    /**
     * PHP 8.4 PropertyHookType: string-backed enum for property hook reflection (#7222, #28345).
     *
     * php-src: ext/reflection/php_reflection.stub.php —
     *   enum PropertyHookType: string { case Get = 'get'; case Set = 'set'; }
     */
    private static function registerPropertyHookType(Context $ctx): void
    {
        if (isset($ctx->classes['propertyhooktype'])) {
            return;
        }

        $entry = new ClassEntry('PropertyHookType');
        $entry->isEnum = true;
        $entry->backedType = 'string';

        self::registerStringBackedEnumCase($entry, 'Get', 'get');
        self::registerStringBackedEnumCase($entry, 'Set', 'set');

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $lc = 'propertyhooktype';
        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    /**
     * PHP 8.4 PadType: int-backed enum for str_pad() 4th parameter (#7282).
     *
     * php-src: ext/standard/basic_functions.stub.php — enum PadType: int
     */
    private static function registerPadType(Context $ctx): void
    {
        if (isset($ctx->classes['padtype'])) {
            return;
        }

        $entry = new ClassEntry('PadType');
        $entry->isEnum = true;
        $entry->backedType = 'int';

        self::registerBackedEnumCase($entry, 'Right', 0);
        self::registerBackedEnumCase($entry, 'Left', 1);
        self::registerBackedEnumCase($entry, 'Both', 2);

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $lc = 'padtype';
        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    /**
     * Historical ArrayPadType registration (#17240, #14993) — never enabled under php-src-strict (#24002).
     *
     * php-src has no ArrayPadType; {@see CompilerVersion::supportsArrayPadTypeEnum()} is always false.
     */
    private static function registerArrayPadType(Context $ctx): void
    {
        if (isset($ctx->classes['arraypadtype'])) {
            return;
        }

        $entry = new ClassEntry('ArrayPadType');
        $entry->isEnum = true;

        self::registerPureEnumCase($entry, 'Positive');
        self::registerPureEnumCase($entry, 'Negative');

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $lc = 'arraypadtype';
        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    /**
     * PHP 8.4 RoundingMode: unit enum for round() mode (#5934, #28535).
     *
     * php-src: ext/standard/basic_functions.stub.php — enum RoundingMode (not int-backed).
     * Case → int mapping for round() stays in {@see VmRoundMode::roundModeIntFromCaseName()}.
     */
    private static function registerRoundingMode(Context $ctx): void
    {
        if (isset($ctx->classes['roundingmode'])) {
            return;
        }

        $entry = new ClassEntry('RoundingMode');
        $entry->isEnum = true;

        foreach ([
            'HalfAwayFromZero',
            'HalfTowardsZero',
            'HalfEven',
            'HalfOdd',
            'TowardsZero',
            'AwayFromZero',
            'NegativeInfinity',
            'PositiveInfinity',
        ] as $caseName) {
            self::registerPureEnumCase($entry, $caseName);
        }

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $lc = 'roundingmode';
        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    /**
     * PHP 8.4 InfoView: int-backed enum for phpinfo() flags (#7285).
     *
     * php-src: ext/standard/basic_functions.stub.php — enum InfoView: int
     */
    private static function registerInfoView(Context $ctx): void
    {
        if (isset($ctx->classes['infoview'])) {
            return;
        }

        $entry = new ClassEntry('InfoView');
        $entry->isEnum = true;
        $entry->backedType = 'int';

        self::registerBackedEnumCase($entry, 'All', VmInfo::INFO_ALL);
        self::registerBackedEnumCase($entry, 'General', VmInfo::INFO_GENERAL);
        self::registerBackedEnumCase($entry, 'Credits', VmInfo::INFO_CREDITS);
        self::registerBackedEnumCase($entry, 'Configuration', VmInfo::INFO_CONFIGURATION);
        self::registerBackedEnumCase($entry, 'Modules', VmInfo::INFO_MODULES);
        self::registerBackedEnumCase($entry, 'Environment', VmInfo::INFO_ENVIRONMENT);
        self::registerBackedEnumCase($entry, 'Variables', VmInfo::INFO_VARIABLES);
        self::registerBackedEnumCase($entry, 'License', VmInfo::INFO_LICENSE);

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $lc = 'infoview';
        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    /**
     * PHP 8.3 ClockInterface: int-backed enum for clock_gettime() (#11624).
     *
     * php-src: ext/standard/basic_functions.stub.php — enum ClockInterface: int
     */
    private static function registerClockInterface(Context $ctx): void
    {
        if (isset($ctx->classes['clockinterface'])) {
            return;
        }

        $entry = new ClassEntry('ClockInterface');
        $entry->isEnum = true;
        $entry->backedType = 'int';

        self::registerBackedEnumCase($entry, 'Realtime', VmHrtimeNative::CLOCK_REALTIME);
        self::registerBackedEnumCase($entry, 'Monotonic', VmHrtimeNative::CLOCK_MONOTONIC);

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $lc = 'clockinterface';
        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    private static function registerPureEnumCase(ClassEntry $enum, string $name): void
    {
        $lc = \PHPCompiler\ClassConstName::key($name);
        $null = new Variable();
        $null->null();
        $case = EnumCaseSupport::createCase($enum, $name, $null);
        $enum->constants[$lc] = $case;
        $enum->enumCaseCanonicalNames[$lc] = $name;
        $enum->enumCases[] = [
            'name' => $name,
            'value' => $null,
        ];
    }

    private static function registerBackedEnumCase(ClassEntry $enum, string $name, int $value): void
    {
        $lc = \PHPCompiler\ClassConstName::key($name);
        $backing = new Variable();
        $backing->int($value);
        $case = EnumCaseSupport::createCase($enum, $name, $backing);
        $enum->constants[$lc] = $case;
        $enum->enumCaseCanonicalNames[$lc] = $name;
        $enum->enumCases[] = [
            'name' => $name,
            'value' => $backing,
        ];
    }

    private static function registerStringBackedEnumCase(ClassEntry $enum, string $name, string $value): void
    {
        $lc = \PHPCompiler\ClassConstName::key($name);
        $backing = new Variable();
        $backing->string($value);
        $case = EnumCaseSupport::createCase($enum, $name, $backing);
        $enum->constants[$lc] = $case;
        $enum->enumCaseCanonicalNames[$lc] = $name;
        $enum->enumCases[] = [
            'name' => $name,
            'value' => $backing,
        ];
    }
}
