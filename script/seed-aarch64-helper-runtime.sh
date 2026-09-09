#!/usr/bin/env bash
# Seed / expand the committed aarch64-linux helper-runtime tier (#36391).
#
# Cross-emits a curated helper set under PHP_COMPILER_TARGET=aarch64-linux
# (object emit only — no link) and publishes into prelinked/helper-runtime/aarch64-linux/.
# Full corpus refresh still needs a native aarch64 host (or a longer QEMU job).
#
# Usage:
#   ./script/seed-aarch64-helper-runtime.sh
#   ./script/seed-aarch64-helper-runtime.sh --check   # assert seed count + ELF only
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Curated seed: every VM_* and lib_VM_* unit already published for x86_64-linux,
# plus ext/standard tiers (array/string introspection, then array functional +
# string encode used near aot-smoke / web examples).
# Keep in sync with x86_64-linux published slugs — do not invent units missing there.
SEED_UNITS=(
  /VM/AttributeNewInstanceJitHelper.php
  /VM/ClosureBindJitHelper.php
  /VM/CoalesceJitHelper.php
  /VM/EnumCasesJitHelper.php
  /VM/InOperatorJitHelper.php
  /VM/InstanceOfJitHelper.php
  /VM/NullsafeJitHelper.php
  /VM/PropertyIsInitializedJitHelper.php
  /VM/SensitiveParamJitHelper.php
  /VM/SplArrayCastJitHelper.php
  /VM/TryCatchJitHelper.php
  /VM/ValueEchoJitHelper.php
  /VM/VariableFunctionCallJitHelper.php
  /lib/VM/CompareJitHelperScalars.php
  /lib/VM/LateStaticJitHelper.php
  /lib/VM/NonObjectPropertyFetchJitHelper.php
  /lib/VM/RegexIteratorFilterJitHelper.php
  /lib/VM/ScalarDimFetchJitHelper.php
  /lib/VM/ShiftOperandJitHelper.php
  /lib/VM/StringOffsetJitHelper.php
  /lib/VM/UndefinedPropertyFetchJitHelper.php
  /lib/VM/VmVarFetchJitHelper.php
  /ext/standard/ArrayChunkJitHelper.php
  /ext/standard/ArrayIsListJitHelper.php
  /ext/standard/ArraySliceJitHelper.php
  /ext/standard/Bin2hexJitHelper.php
  /ext/standard/CountCharsJitHelper.php
  /ext/standard/Crc32JitHelper.php
  /ext/standard/PrintRJitHelper.php
  /ext/standard/StrWordCountJitHelper.php
  /ext/standard/SubstrCountJitHelper.php
  /ext/standard/VarExportJitHelper.php
  # Array functional + string encode tier (#36391 after #36858)
  /ext/standard/ArrayMapJitHelper.php
  /ext/standard/ArrayWalkJitHelper.php
  /ext/standard/ArrayReduceJitHelper.php
  /ext/standard/ArrayFindJitHelper.php
  /ext/standard/ArrayMergeRecursiveJitHelper.php
  /ext/standard/ArrayCountRecursiveJitHelper.php
  /ext/standard/ArrayElemJitHelper.php
  /ext/standard/Base64JitHelper.php
  /ext/standard/Hex2binJitHelper.php
  /ext/standard/FindSubstrJitHelper.php
  # String transform / HTML / escapes tier (#36391 after #36864)
  /ext/standard/StrReplaceJitHelper.php
  /ext/standard/StrPadJitHelper.php
  /ext/standard/StrRepeatJitHelper.php
  /ext/standard/StrrevJitHelper.php
  /ext/standard/StripTagsJitHelper.php
  /ext/standard/StripslashesJitHelper.php
  /ext/standard/AddslashesJitHelper.php
  /ext/standard/HtmlspecialcharsJitHelper.php
  /ext/standard/Nl2brJitHelper.php
  /ext/standard/UcwordsJitHelper.php
  # URL / query / JSON / sprintf web tier (#36391 after #36869)
  /ext/standard/UrlencodeJitHelper.php
  /ext/standard/UrldecodeJitHelper.php
  /ext/standard/ParseUrlJitHelper.php
  /ext/standard/HttpBuildQueryJitHelper.php
  /ext/standard/ParseStrJitHelper.php
  /ext/standard/JsonDecodeJitHelper.php
  /ext/standard/JsonEncodeNestedJitHelper.php
  /ext/standard/JsonValidateJitHelper.php
  /ext/standard/SprintfJitHelper.php
  /ext/standard/HttpResponseJitHelper.php
  # HTML decode / file I/O / class introspect tier (#36391 after #37015)
  /ext/standard/HtmlEntitiesJitHelper.php
  /ext/standard/HtmlEntityDecodeJitHelper.php
  /ext/standard/HtmlspecialcharsDecodeJitHelper.php
  /ext/standard/ChunkSplitJitHelper.php
  /ext/standard/HashEqualsJitHelper.php
  /ext/standard/FileGetContentsJitHelper.php
  /ext/standard/FilePutContentsJitHelper.php
  /ext/standard/ClassExistsJitHelper.php
  /ext/standard/FunctionExistsJitHelper.php
  /ext/standard/GetObjectVarsJitHelper.php
  # OO hierarchy / method-property introspect tier (#36391 after #37021)
  /ext/standard/InterfaceExistsJitHelper.php
  /ext/standard/TraitExistsJitHelper.php
  /ext/standard/EnumExistsJitHelper.php
  /ext/standard/MethodExistsJitHelper.php
  /ext/standard/PropertyExistsJitHelper.php
  /ext/standard/ClassImplementsJitHelper.php
  /ext/standard/ClassParentsJitHelper.php
  /ext/standard/ClassUsesJitHelper.php
  /ext/standard/GetClassMethodsJitHelper.php
  /ext/standard/GetParentClassJitHelper.php
  # Class vars / type / array-assoc / sort tier (#36391 after #37059)
  /ext/standard/GetClassVarsJitHelper.php
  /ext/standard/ClassUsesRecursiveJitHelper.php
  /ext/standard/UnitEnumExistsJitHelper.php
  /ext/standard/SettypeJitHelper.php
  /ext/standard/ArrayDiffAssocJitHelper.php
  /ext/standard/ArrayIntersectAssocJitHelper.php
  /ext/standard/ArrayReplaceKeyJitHelper.php
  /ext/standard/SortJitHelper.php
  /ext/standard/UsortJitHelper.php
  /ext/standard/RoundJitHelper.php
  # Math / string / path / cwd / fs tier (#36391 after #37067)
  /ext/standard/PowIntJitHelper.php
  /ext/standard/ClampJitHelper.php
  /ext/standard/WordwrapJitHelper.php
  /ext/standard/QuotemetaJitHelper.php
  /ext/standard/PathinfoJitHelper.php
  /ext/standard/GetcwdJitHelper.php
  /ext/standard/ChdirJitHelper.php
  /ext/standard/FsDirJitHelper.php
  /ext/standard/FsGlobJitHelper.php
  /ext/standard/TempnamJitHelper.php
  # Time / datetime / fstat / vsprintf / substr_compare tier (#36391 after #37074)
  /ext/standard/MicrotimeJitHelper.php
  /ext/standard/StrtotimeJitHelper.php
  /ext/standard/StrftimeJitHelper.php
  /ext/standard/TimezoneOffsetJitHelper.php
  /ext/standard/FormatDatetimeJitHelper.php
  /ext/standard/DateTimeFormatJitHelper.php
  /ext/standard/GettimeofdayJitHelper.php
  /ext/standard/FstatJitHelper.php
  /ext/standard/VsprintfJitHelper.php
  /ext/standard/SubstrCompareJitHelper.php
  # String compare / CSV / escapes / metaphone tier (#36391 after #37076)
  /ext/standard/CaseCompareJitHelper.php
  /ext/standard/NCompareJitHelper.php
  /ext/standard/CharInMaskJitHelper.php
  /ext/standard/LevenshteinJitHelper.php
  /ext/standard/CslashesJitHelper.php
  /ext/standard/CsvFputcsvJitHelper.php
  /ext/standard/CsvStrGetcsvJitHelper.php
  /ext/standard/ConvertUuJitHelper.php
  /ext/standard/HebrevJitHelper.php
  /ext/standard/MetaphoneJitHelper.php
  # Timezone / clock / date tier (#36391 after #37084)
  /ext/standard/ClockGettimeJitHelper.php
  /ext/standard/DefaultTimezoneJitHelper.php
  /ext/standard/DefaultTimezoneCivilJitHelper.php
  /ext/standard/DateIntervalFormatJitHelper.php
  /ext/standard/GmgetdateJitHelper.php
  /ext/standard/GmmktimeJitHelper.php
  /ext/standard/MktimeJitHelper.php
  /ext/standard/TimeJitHelper.php
  /ext/standard/TimezoneLocationJitHelper.php
  /ext/standard/HrtimeJitHelper.php
  # Network / host / process / dns tier (#36391 after #37089)
  /ext/standard/CheckdnsrrJitHelper.php
  /ext/standard/GethostbyaddrJitHelper.php
  /ext/standard/GetHeadersJitHelper.php
  /ext/standard/GethostnameJitHelper.php
  /ext/standard/GetmypidJitHelper.php
  /ext/standard/GetrusageJitHelper.php
  /ext/standard/GetoptJitHelper.php
  /ext/standard/InetJitHelper.php
  /ext/standard/FtokJitHelper.php
  /ext/standard/FnmatchJitHelper.php
  # Env / error / ini / execution tier (#36391 after #37094)
  /ext/standard/EnvLocalJitHelper.php
  /ext/standard/EnvironMirrorNativeJitHelper.php
  /ext/standard/ErrorLastJitHelper.php
  /ext/standard/ErrorLogJitHelper.php
  /ext/standard/ErrorSilenceJitHelper.php
  /ext/standard/ExecutionLimitsJitHelper.php
  /ext/standard/IniIntrospectionJitHelper.php
  /ext/standard/IniParseQuantityJitHelper.php
  /ext/standard/ParseIniNativeJitHelper.php
  /ext/standard/ProcNiceJitHelper.php
  # Math / random / memory / soundex / sys / version / uniqid tier (#36391 after #37102)
  # Frexp/Ldexp/Modf/Nextafter are algorithm SSOT only (no HELPER_PATH) — skip.
  /ext/standard/MathBaseConvertJitHelper.php
  /ext/standard/LcgJitHelper.php
  /ext/standard/RandJitHelper.php
  /ext/standard/RandomBytesJitHelper.php
  /ext/standard/MemoryJitHelper.php
  /ext/standard/SoundexJitHelper.php
  /ext/standard/UniqidJitHelper.php
  /ext/standard/VersionCompareJitHelper.php
  /ext/standard/SysGetTempDirJitHelper.php
  /ext/standard/SysGetloadavgJitHelper.php
  # Filesystem / link / dir / readfile / stat tier (#36391 after #37155)
  /ext/standard/LinkJitHelper.php
  /ext/standard/ReadlinkJitHelper.php
  /ext/standard/SymlinkJitHelper.php
  /ext/standard/RenameJitHelper.php
  /ext/standard/ReadfileJitHelper.php
  /ext/standard/StatArrayJitHelper.php
  /ext/standard/StatCacheJitHelper.php
  /ext/standard/OpendirJitHelper.php
  /ext/standard/DirHandleJitHelper.php
  /ext/standard/DirSnapshotJitHelper.php
  # Stream core / fopen / filter / path tier (#36391 after #37225)
  /ext/standard/StreamBucketJitHelper.php
  /ext/standard/StreamBufferJitHelper.php
  /ext/standard/StreamCapsJitHelper.php
  /ext/standard/StreamErrorStoreJitHelper.php
  /ext/standard/StreamFilterJitHelper.php
  /ext/standard/StreamIncludeOpenJitHelper.php
  /ext/standard/StreamIoJitHelper.php
  /ext/standard/StreamLibcHandleJitHelper.php
  /ext/standard/StreamLifecycleJitHelper.php
  /ext/standard/StreamMetaJitHelper.php
  # Stream mode / path / socket + include / mime / vardump / strrot13 (#36391 after #37232)
  /ext/standard/StreamModeJitHelper.php
  /ext/standard/StreamNotificationJitHelper.php
  /ext/standard/StreamPathJitHelper.php
  /ext/standard/StreamSocketAcceptJitHelper.php
  /ext/standard/StreamSocketGetNameJitHelper.php
  /ext/standard/StreamSocketPairJitHelper.php
  /ext/standard/IncludePathResolverJitHelper.php
  /ext/standard/MimeContentTypeJitHelper.php
  /ext/standard/VarDumpJitHelper.php
  /ext/standard/StrRot13JitHelper.php
  # OB / serialize / password / sleep / process (#36391 after #37246)
  # Preg* skipped: tip nested compile misses Compiler\Concern\OpCode (see #37246).
  # Shuffle skipped: algorithm SSOT only (no HELPER_PATH) — same class as Frexp.
  /ext/standard/ObOutputJitHelper.php
  /ext/standard/ObStatusJitHelper.php
  /ext/standard/ObGzhandlerJitHelper.php
  /ext/standard/ObOutputExecCaptureJitHelper.php
  /ext/standard/SerializeNestedJitHelper.php
  /ext/standard/SerializeObjectNestedJitHelper.php
  /ext/standard/PasswordJitHelper.php
  /ext/standard/SleepJitHelper.php
  /ext/standard/ProcessIdentityJitHelper.php
  /ext/standard/ProcessSlotJitHelper.php
  # Unserialize / hash-crypt / syslog / gzip-zlib / session (#36391 after #37323)
  /ext/standard/UnserializeJitHelper.php
  /ext/standard/UnserializeObjectNestedJitHelper.php
  /ext/standard/HashCryptoJitHelper.php
  /ext/standard/LibcryptJitHelper.php
  /ext/standard/SyslogJitHelper.php
  /ext/standard/GzStreamJitHelper.php
  /ext/standard/ZlibJitHelper.php
  /ext/standard/SessionNameJitHelper.php
  /ext/standard/SessionCreateIdJitHelper.php
  /ext/standard/SessionStorageJitHelper.php
  # Session leftovers / GC / spl_autoload / string (#36391 after #37331)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf skipped: __init__ sealed during NestedJIT (prop_ht_done / ZendDoubleStringRuntime).
  /ext/standard/SessionGcJitHelper.php
  /ext/standard/SessionStartOptionsAotJitHelper.php
  /ext/standard/GcToggleJitHelper.php
  /ext/standard/GcCollectCyclesJitHelper.php
  /ext/standard/GcCollectCyclesRegistryJitHelper.php
  /ext/standard/SplAutoloadJitHelper.php
  /ext/standard/SplAutoloadDefaultJitHelper.php
  /ext/standard/StripWhitespaceJitHelper.php
  /ext/standard/StrIncdecJitHelper.php
  /ext/standard/SuperglobalNameJitHelper.php
  # GC destruct companions + convert_cyr / chroot / image_type / quot_print / strxfrm (#36391 after #37352)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  /ext/standard/GcDestructAllowDelrefJitHelper.php
  /ext/standard/GcDestructShutdownJitHelper.php
  /ext/standard/GcDestructTryInvokeJitHelper.php
  /ext/standard/GcObjectReleaseStorageJitHelper.php
  /ext/standard/ConvertCyrStringJitHelper.php
  /ext/standard/ChrootJitHelper.php
  /ext/standard/ImageTypeToExtensionJitHelper.php
  /ext/standard/ImageTypeToMimeTypeJitHelper.php
  /ext/standard/QuotPrintJitHelper.php
  /ext/standard/StrxfrmJitHelper.php
  # Locale / network / info / assert / strptime / metatags / clone / weakref / list (#36391 after 232)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  # Gethostbynamel skipped: NestedJIT missing __compiler_stream_resolve_include_path.
  # Ini skipped: IniGetLeafJitHelper not compiled under helper-runtime-emit NestedJIT.
  /ext/standard/AssertOptionsJitHelper.php
  /ext/standard/NlLanginfoJitHelper.php
  /ext/standard/NetInterfacesJitHelper.php
  /ext/standard/StrptimeJitHelper.php
  /ext/standard/MetaTagsJitHelper.php
  /ext/standard/GlobalIntrospectionNameJitHelper.php
  /ext/standard/InfoJitHelper.php
  /ext/standard/CloneWithJitHelper.php
  /ext/standard/WeakRefRegistryJitHelper.php
  /ext/standard/ListSpreadTailJitHelper.php
  # Apache / highlight / headers / phpinfo / readonly / request body / scope / upload / net lookup (#36391 after 242)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  # Gethostbynamel skipped: NestedJIT missing __compiler_stream_resolve_include_path.
  # Ini skipped: IniGetLeafJitHelper not compiled under helper-runtime-emit NestedJIT.
  # Progress skipped: NestedJIT ContextLlvmConstantsAndRegistry seal during helper emit.
  /ext/standard/ApacheNoteJitHelper.php
  /ext/standard/HighlightJitHelper.php
  /ext/standard/PendingHeadersJitHelper.php
  /ext/standard/PhpinfoJitHelper.php
  /ext/standard/ReadonlyRaiseJitHelper.php
  /ext/standard/RequestParseBodyJitHelper.php
  /ext/standard/RequestParseBodyNativeJitHelper.php
  /ext/standard/ScopeBuiltinJitHelper.php
  /ext/standard/UploadTempJitHelper.php
  /ext/standard/NetworkServicesNameLookupThinAot.php
  # First non-standard tier: ctype + calendar + posix getters (#36391 after 252)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  # Gethostbynamel skipped: NestedJIT missing __compiler_stream_resolve_include_path.
  # Ini skipped: IniGetLeafJitHelper not compiled under helper-runtime-emit NestedJIT.
  # Progress skipped: NestedJIT ContextLlvmConstantsAndRegistry seal during helper emit.
  /ext/ctype/CtypeJitHelper.php
  /ext/calendar/CalDaysInMonthJitHelper.php
  /ext/calendar/EasterDaysJitHelper.php
  /ext/calendar/GregoriantojdJitHelper.php
  /ext/calendar/JdtogregorianJitHelper.php
  /ext/calendar/JdtounixJitHelper.php
  /ext/calendar/UnixtojdJitHelper.php
  /ext/posix/PosixGetpidJitHelper.php
  /ext/posix/PosixGetuidJitHelper.php
  /ext/posix/PosixStrerrorJitHelper.php
  # More calendar conversions + posix getters (#36391 after 262)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  # Gethostbynamel skipped: NestedJIT missing __compiler_stream_resolve_include_path.
  # Ini skipped: IniGetLeafJitHelper not compiled under helper-runtime-emit NestedJIT.
  # Progress skipped: NestedJIT ContextLlvmConstantsAndRegistry seal during helper emit.
  # PosixSet* / PosixSession / PosixTerminal deferred (mutators / tty).
  /ext/calendar/CalFromJdJitHelper.php
  /ext/calendar/CalToJdJitHelper.php
  /ext/calendar/EasterDateJitHelper.php
  /ext/calendar/FrenchtojdJitHelper.php
  /ext/calendar/JdmonthnameJitHelper.php
  /ext/calendar/JuliantojdJitHelper.php
  /ext/posix/PosixGetegidJitHelper.php
  /ext/posix/PosixGeteuidJitHelper.php
  /ext/posix/PosixGetgidJitHelper.php
  /ext/posix/PosixGetppidJitHelper.php
  # Remaining calendar JD + safe posix reads + filter scalar/sanitize (#36391 after 272)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  # Gethostbynamel skipped: NestedJIT missing __compiler_stream_resolve_include_path.
  # Ini skipped: IniGetLeafJitHelper not compiled under helper-runtime-emit NestedJIT.
  # Progress skipped: NestedJIT ContextLlvmConstantsAndRegistry seal during helper emit.
  # CalInfo still skipped (unknown unit under NestedJIT).
  # PosixSet* / PosixSession / PosixTerminal deferred (mutators / tty).
  /ext/calendar/JdtofrenchJitHelper.php
  /ext/calendar/JdtojewishJitHelper.php
  /ext/calendar/JdtojulianJitHelper.php
  /ext/calendar/JewishtojdJitHelper.php
  /ext/posix/PosixCtermidJitHelper.php
  /ext/posix/PosixTimesJitHelper.php
  /ext/filter/FilterIntJitHelper.php
  /ext/filter/FilterFloatJitHelper.php
  /ext/filter/FilterBooleanJitHelper.php
  /ext/filter/FilterSanitizeJitHelper.php
  # Remaining filter validators + hash + tokenizer + MbStrlen (#36391 after 282)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  # Gethostbynamel skipped: NestedJIT missing __compiler_stream_resolve_include_path.
  # Ini skipped: IniGetLeafJitHelper not compiled under helper-runtime-emit NestedJIT.
  # Progress skipped: NestedJIT ContextLlvmConstantsAndRegistry seal during helper emit.
  # CalInfo still skipped (unknown unit under NestedJIT).
  # PosixSet* / PosixSession / PosixTerminal deferred (mutators / tty).
  /ext/filter/FilterBatchJitHelper.php
  /ext/filter/FilterDomainValidate.php
  /ext/filter/FilterEmailValidate.php
  /ext/filter/FilterIpValidate.php
  /ext/filter/FilterMacValidate.php
  /ext/filter/FilterUrlValidate.php
  /ext/hash/HashAlgosJitHelper.php
  /ext/hash/HashContextJitHelper.php
  /ext/tokenizer/TokenGetAllJitHelper.php
  /ext/mbstring/MbStrlenJitHelper.php
  # More mbstring string/width/encoding helpers (#36391 after 292)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  # Gethostbynamel skipped: NestedJIT missing __compiler_stream_resolve_include_path.
  # Ini skipped: IniGetLeafJitHelper not compiled under helper-runtime-emit NestedJIT.
  # Progress skipped: NestedJIT ContextLlvmConstantsAndRegistry seal during helper emit.
  # CalInfo still skipped (unknown unit under NestedJIT).
  # PosixSet* / PosixSession / PosixTerminal deferred (mutators / tty).
  # MbEreg/MbSplit/MbSearch/MbConvert* deferred to a later mbstring wave.
  /ext/mbstring/MbStrwidthJitHelper.php
  /ext/mbstring/MbStrcutJitHelper.php
  /ext/mbstring/MbStrSplitJitHelper.php
  /ext/mbstring/MbSubstrCountJitHelper.php
  /ext/mbstring/MbTrimJitHelper.php
  /ext/mbstring/MbScrubJitHelper.php
  /ext/mbstring/MbCheckEncodingJitHelper.php
  /ext/mbstring/MbChrOrdJitHelper.php
  /ext/mbstring/MbPreferredMimeNameJitHelper.php
  /ext/mbstring/MbEncodingAliasesJitHelper.php
  # mbstring encoding/config/case helpers (#36391 after 302)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  # Gethostbynamel skipped: NestedJIT missing __compiler_stream_resolve_include_path.
  # Ini skipped: IniGetLeafJitHelper not compiled under helper-runtime-emit NestedJIT.
  # Progress skipped: NestedJIT ContextLlvmConstantsAndRegistry seal during helper emit.
  # CalInfo still skipped (unknown unit under NestedJIT).
  # PosixSet* / PosixSession / PosixTerminal deferred (mutators / tty).
  # MbEreg/MbSplit/MbSearch/MbConvertKana/MbConvertVariables/MbNumericEntity/
  # MbOutputHandler/MbRegexEncoding deferred to a later mbstring wave.
  /ext/mbstring/MbInternalEncodingJitHelper.php
  /ext/mbstring/MbLanguageJitHelper.php
  /ext/mbstring/MbDetectEncodingJitHelper.php
  /ext/mbstring/MbDetectOrderJitHelper.php
  /ext/mbstring/MbSubstituteCharacterJitHelper.php
  /ext/mbstring/MbHttpInputJitHelper.php
  /ext/mbstring/MbHttpOutputJitHelper.php
  /ext/mbstring/MbCaseJitHelper.php
  /ext/mbstring/MbConvertCaseJitHelper.php
  /ext/mbstring/MbConvertEncodingJitHelper.php
  # Remaining mbstring (ereg/search/split/kana/entity/output) + first iconv (#36391 after 312)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  # Gethostbynamel skipped: NestedJIT missing __compiler_stream_resolve_include_path.
  # Ini skipped: IniGetLeafJitHelper not compiled under helper-runtime-emit NestedJIT.
  # Progress skipped: NestedJIT ContextLlvmConstantsAndRegistry seal during helper emit.
  # CalInfo still skipped (unknown unit under NestedJIT).
  # PosixSet* / PosixSession / PosixTerminal deferred (mutators / tty).
  # MbGetInfo / MbMimeheader not published on x86_64-linux yet — skip.
  /ext/mbstring/MbConvertKanaJitHelper.php
  /ext/mbstring/MbConvertVariablesJitHelper.php
  /ext/mbstring/MbEregJitHelper.php
  /ext/mbstring/MbNumericEntityJitHelper.php
  /ext/mbstring/MbOutputHandlerJitHelper.php
  /ext/mbstring/MbRegexEncodingJitHelper.php
  /ext/mbstring/MbSearchJitHelper.php
  /ext/mbstring/MbSplitJitHelper.php
  /ext/iconv/IconvJitHelper.php
  /ext/iconv/IconvStringJitHelper.php
  # Finish iconv + compress/encode/strerror tier (#36391 after 322)
  # Frexp/Ldexp/Modf/Nextafter/Shuffle still skipped (algorithm SSOT, no HELPER_PATH).
  # Preg* still skipped: tip nested compile misses Compiler\Concern\OpCode.
  # Sscanf still skipped: __init__ sealed during NestedJIT.
  # Gethostbynamel skipped: NestedJIT missing __compiler_stream_resolve_include_path.
  # Ini skipped: IniGetLeafJitHelper not compiled under helper-runtime-emit NestedJIT.
  # Progress skipped: NestedJIT ContextLlvmConstantsAndRegistry seal during helper emit.
  # CalInfo still skipped (unknown unit under NestedJIT).
  # PosixSet* / PosixSession / PosixTerminal deferred (mutators / tty).
  # Curl* handle/transfer deferred (host libcurl); Strerror* are string-only.
  # Dom* / Intl* / Openssl* / Soap* / Sockets* deferred to later waves.
  /ext/iconv/IconvMimeJitHelper.php
  /ext/bcmath/BcmathJitHelper.php
  /ext/bz2/Bz2JitHelper.php
  /ext/bz2/Bz2StreamJitHelper.php
  /ext/gettext/GettextJitHelper.php
  /ext/exif/ExifImagetypeJitHelper.php
  /ext/fileinfo/FinfoFileJitHelper.php
  /ext/curl/CurlStrerrorJitHelper.php
  /ext/curl/CurlShareStrerrorJitHelper.php
  /ext/lzf/LzfJitHelper.php
)

MIN_SEED=${#SEED_UNITS[@]}
ARCH_DIR="$ROOT/prelinked/helper-runtime/aarch64-linux"
UNITS_DIR="$ARCH_DIR/units"

check_seed() {
  local count=0
  local bad=0
  local u slug o
  if [[ ! -d "$UNITS_DIR" ]]; then
    echo "seed-aarch64-helper-runtime: missing $UNITS_DIR" >&2
    exit 1
  fi
  for u in "${SEED_UNITS[@]}"; do
    slug=$(php -r 'require "vendor/autoload.php"; echo PHPCompiler\AOT\HelperRuntimeCache::slugFor($argv[1]);' "$u")
    o="$UNITS_DIR/$slug/unit.o"
    if [[ ! -f "$o" ]]; then
      echo "seed-aarch64-helper-runtime: MISSING $slug" >&2
      bad=$((bad + 1))
      continue
    fi
    machine=$(php -r 'require "vendor/autoload.php"; $m=PHPCompiler\AOT\CompileTarget::readElfMachine($argv[1]); echo $m===null?"null":(string)$m;' "$o")
    if [[ "$machine" != "183" ]]; then
      echo "seed-aarch64-helper-runtime: ELF mismatch $slug e_machine=$machine (want 183)" >&2
      bad=$((bad + 1))
      continue
    fi
    count=$((count + 1))
  done
  echo "seed-aarch64-helper-runtime: check — $count/${MIN_SEED} seed units EM_AARCH64"
  if (( bad > 0 || count < MIN_SEED )); then
    exit 1
  fi
  # Empty result set is not a pass.
  if (( count < 1 )); then
    echo "seed-aarch64-helper-runtime: empty seed — not a pass" >&2
    exit 1
  fi
}

if [[ "${1:-}" == "--check" ]]; then
  check_seed
  exit 0
fi

export PHP_COMPILER_TARGET=aarch64-linux
# Unique cache dir — concurrent helper caches corrupt each other.
export PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR="${PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR:-$ROOT/build/helper-runtime-cache-aarch64-seed-$$}"
mkdir -p "$PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR"

echo "seed-aarch64-helper-runtime: TARGET=$PHP_COMPILER_TARGET cache=$PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR"
echo "seed-aarch64-helper-runtime: emitting ${#SEED_UNITS[@]} units…"

failed=0
for u in "${SEED_UNITS[@]}"; do
  echo "  emit $u"
  if ! php script/emit-helper-runtime-object.php --unit="$u"; then
    echo "seed-aarch64-helper-runtime: emit FAILED $u" >&2
    failed=$((failed + 1))
  fi
done

if (( failed > 0 )); then
  echo "seed-aarch64-helper-runtime: $failed emit failure(s)" >&2
  exit 1
fi

echo "seed-aarch64-helper-runtime: publishing…"
php script/publish-helper-units-prelink.php "${SEED_UNITS[@]}"

# Refresh README seed count line is left to the caller / PR; assert ELF here.
check_seed
echo "seed-aarch64-helper-runtime: OK"
