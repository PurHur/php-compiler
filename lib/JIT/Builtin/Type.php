<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;

class Type extends Builtin {

    public Type\String_ $string;
    public Type\Object_ $object;
    public Type\Value $value;
    public Type\HashTable $hashtable;
    protected array $fields;

    public function register(): void {
        $this->string = new Type\String_($this->context, $this->loadType);
        $this->object = new Type\Object_($this->context, $this->loadType);
        $this->value = new Type\Value($this->context, $this->loadType);
        $this->hashtable = new Type\HashTable($this->context, $this->loadType);
        // $this->maskedarray = new Type\MaskedArray($this->context, $this->loadType);
        // $this->nativearray = new Type\NativeArray($this->context, $this->loadType);
        $this->string->register();
        $this->value->register();
        $this->object->register();
        $this->hashtable->register();
        $fntypeGetenv = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnGetenv = $this->context->module->addFunction('__compiler_getenv', $fntypeGetenv);
        $this->context->registerFunction('__compiler_getenv', $fnGetenv);
        $fntypeDeployPath = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnDeployPath = $this->context->module->addFunction(
            '__compiler_phpc_deploy_path',
            $fntypeDeployPath
        );
        $this->context->registerFunction('__compiler_phpc_deploy_path', $fnDeployPath);
        $fntypeNumberFormat = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('double'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnNumberFormat = $this->context->module->addFunction('__compiler_number_format', $fntypeNumberFormat);
        $this->context->registerFunction('__compiler_number_format', $fnNumberFormat);
        $fntypeSprintf = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnSprintf = $this->context->module->addFunction('__compiler_sprintf', $fntypeSprintf);
        $this->context->registerFunction('__compiler_sprintf', $fnSprintf);
        $fntypePack = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnPack = $this->context->module->addFunction('__compiler_pack', $fntypePack);
        $this->context->registerFunction('__compiler_pack', $fnPack);
        $fntypeUnpack = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('int64'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnUnpack = $this->context->module->addFunction('__compiler_unpack', $fntypeUnpack);
        $this->context->registerFunction('__compiler_unpack', $fnUnpack);
        $fntypeIniSet = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnIniSet = $this->context->module->addFunction('__compiler_ini_set', $fntypeIniSet);
        $this->context->registerFunction('__compiler_ini_set', $fnIniSet);
        $fntypeIniGet = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__value__*')
        );
        $fnIniGet = $this->context->module->addFunction('__compiler_ini_get', $fntypeIniGet);
        $this->context->registerFunction('__compiler_ini_get', $fnIniGet);
        $fntypeStripTags = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnStripTags = $this->context->module->addFunction('__compiler_strip_tags', $fntypeStripTags);
        $this->context->registerFunction('__compiler_strip_tags', $fnStripTags);
        $i64 = $this->context->getTypeFromString('int64');
        $fntypeUtf8Strlen = $this->context->context->functionType(
            $i64,
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnUtf8Strlen = $this->context->module->addFunction('__compiler_utf8_strlen', $fntypeUtf8Strlen);
        $this->context->registerFunction('__compiler_utf8_strlen', $fnUtf8Strlen);
        HttpResponseCode::implement($this->context);
        SessionId::implement($this->context);
        SessionName::implement($this->context);
        ObOutput::registerExternals($this->context);
        ErrorHandlerOutput::registerExternals($this->context);
        StreamContextOutput::registerExternals($this->context);
        CallArgv::implement($this->context);
        $i8p = $this->context->getTypeFromString('int8*');
        $i32 = $this->context->getTypeFromString('int32');
        $sizeT = $this->context->getTypeFromString('size_t');
        foreach (
            [
                'getenv' => [$i8p, false, $i8p],
                'putenv' => [$i32, false, $i8p],
                'strlen' => [$sizeT, false, $i8p],
            ] as $libcName => [$ret, $vararg, $param]
        ) {
            $ft = $this->context->context->functionType($ret, $vararg, $param);
            $fn = $this->context->module->addFunction($libcName, $ft);
            $this->context->registerFunction($libcName, $fn);
        }
        $i64 = $this->context->getTypeFromString('int64');
        $i8pp = $this->context->getTypeFromString('int8**');
        $voidTy = $this->context->getTypeFromString('void');
        $fnStoreArgv = $this->context->module->addFunction(
            '__phpc_cli_store_argv',
            $this->context->context->functionType($voidTy, false, $i32, $i8pp)
        );
        $this->context->registerFunction('__phpc_cli_store_argv', $fnStoreArgv);
        $fnCliArgc = $this->context->module->addFunction(
            '__phpc_cli_argc',
            $this->context->context->functionType($i64, false)
        );
        $this->context->registerFunction('__phpc_cli_argc', $fnCliArgc);
        $fnCliArgvCstr = $this->context->module->addFunction(
            '__phpc_cli_argv_cstr',
            $this->context->context->functionType($i8p, false, $i32)
        );
        $this->context->registerFunction('__phpc_cli_argv_cstr', $fnCliArgvCstr);
        $fnCliStrEq = $this->context->module->addFunction(
            '__phpc_cli_str_eq',
            $this->context->context->functionType($i32, false, $i8p, $i8p)
        );
        $this->context->registerFunction('__phpc_cli_str_eq', $fnCliStrEq);
        $fnProgressNote = $this->context->module->addFunction(
            '__phpc_progress_note',
            $this->context->context->functionType($voidTy, false, $i8p)
        );
        $this->context->registerFunction('__phpc_progress_note', $fnProgressNote);
        $valuePtr = $this->context->getTypeFromString('__value__*');
        $fnRefreshArgv = $this->context->module->addFunction(
            '__phpc_cli_refresh_argv_global',
            $this->context->context->functionType($voidTy, false, $valuePtr)
        );
        $this->context->registerFunction('__phpc_cli_refresh_argv_global', $fnRefreshArgv);
        $ftOpen = $this->context->context->functionType($i32, false, $i8p, $i32);
        $fnOpen = $this->context->module->addFunction('open', $ftOpen);
        $this->context->registerFunction('open', $fnOpen);
        $ftFopen = $this->context->context->functionType($i8p, false, $i8p, $i8p);
        $fnFopen = $this->context->module->addFunction('fopen', $ftFopen);
        $this->context->registerFunction('fopen', $fnFopen);
        $ftFwrite = $this->context->context->functionType($sizeT, false, $i8p, $sizeT, $sizeT, $i8p);
        $fnFwrite = $this->context->module->addFunction('fwrite', $ftFwrite);
        $this->context->registerFunction('fwrite', $fnFwrite);
        $ftFclose = $this->context->context->functionType($i32, false, $i8p);
        $fnFclose = $this->context->module->addFunction('fclose', $ftFclose);
        $this->context->registerFunction('fclose', $fnFclose);
        $ftRead = $this->context->context->functionType($i64, false, $i32, $i8p, $sizeT);
        $fnRead = $this->context->module->addFunction('read', $ftRead);
        $this->context->registerFunction('read', $fnRead);
        $ftWrite = $this->context->context->functionType($i64, false, $i32, $i8p, $sizeT);
        $fnWrite = $this->context->module->addFunction('write', $ftWrite);
        $this->context->registerFunction('write', $fnWrite);
        $ftClose = $this->context->context->functionType($i32, false, $i32);
        $fnClose = $this->context->module->addFunction('close', $ftClose);
        $this->context->registerFunction('close', $fnClose);
        $fntypeReadfile = $this->context->context->functionType(
            $i64,
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnReadfile = $this->context->module->addFunction('__compiler_readfile', $fntypeReadfile);
        $this->context->registerFunction('__compiler_readfile', $fnReadfile);
        $fntypeFileGetContents = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnFileGetContents = $this->context->module->addFunction(
            '__compiler_file_get_contents',
            $fntypeFileGetContents
        );
        $this->context->registerFunction('__compiler_file_get_contents', $fnFileGetContents);
        $fntypeFilePutContents = $this->context->context->functionType(
            $i64,
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*'),
            $i64
        );
        $fnFilePutContents = $this->context->module->addFunction(
            '__compiler_file_put_contents',
            $fntypeFilePutContents
        );
        $this->context->registerFunction('__compiler_file_put_contents', $fnFilePutContents);
        $fntypeFwrite = $this->context->context->functionType(
            $i64,
            false,
            $i64,
            $this->context->getTypeFromString('__string__*'),
            $i64
        );
        $fnCompilerFwrite = $this->context->module->addFunction('__compiler_fwrite', $fntypeFwrite);
        $this->context->registerFunction('__compiler_fwrite', $fnCompilerFwrite);
        $strPtr = $this->context->getTypeFromString('__string__*');
        $fntypeFopen = $this->context->context->functionType(
            $i64,
            false,
            $strPtr,
            $strPtr
        );
        $fnFopen = $this->context->module->addFunction('__compiler_fopen', $fntypeFopen);
        $this->context->registerFunction('__compiler_fopen', $fnFopen);
        $fntypeFread = $this->context->context->functionType(
            $strPtr,
            false,
            $i64,
            $i64
        );
        $fnFread = $this->context->module->addFunction('__compiler_fread', $fntypeFread);
        $this->context->registerFunction('__compiler_fread', $fnFread);
        $fntypeFclose = $this->context->context->functionType($i32, false, $i64);
        $fnFclose = $this->context->module->addFunction('__compiler_fclose', $fntypeFclose);
        $this->context->registerFunction('__compiler_fclose', $fnFclose);
        $fntypeIsResource = $this->context->context->functionType($i32, false, $i64);
        $fnIsResource = $this->context->module->addFunction('__compiler_is_resource', $fntypeIsResource);
        $this->context->registerFunction('__compiler_is_resource', $fnIsResource);
        $fntypeGettype = $this->context->context->functionType($strPtr, false, $valuePtr);
        $fnGettype = $this->context->module->addFunction('__compiler_gettype', $fntypeGettype);
        $this->context->registerFunction('__compiler_gettype', $fnGettype);
        $fntypeFlock = $this->context->context->functionType($i32, false, $i64, $i64);
        $fnFlock = $this->context->module->addFunction('__compiler_flock', $fntypeFlock);
        $this->context->registerFunction('__compiler_flock', $fnFlock);
        $fntypeFpassthru = $this->context->context->functionType($i64, false, $i64);
        $fnFpassthru = $this->context->module->addFunction('__compiler_fpassthru', $fntypeFpassthru);
        $this->context->registerFunction('__compiler_fpassthru', $fnFpassthru);
        $fntypeFeof = $this->context->context->functionType($i32, false, $i64);
        $fnFeof = $this->context->module->addFunction('__compiler_feof', $fntypeFeof);
        $this->context->registerFunction('__compiler_feof', $fnFeof);
        $fntypeFflush = $this->context->context->functionType($i32, false, $i64);
        $fnFflush = $this->context->module->addFunction('__compiler_fflush', $fntypeFflush);
        $this->context->registerFunction('__compiler_fflush', $fnFflush);
        $fntypeFtell = $this->context->context->functionType($i64, false, $i64);
        $fnFtell = $this->context->module->addFunction('__compiler_ftell', $fntypeFtell);
        $this->context->registerFunction('__compiler_ftell', $fnFtell);
        $fntypeFgetc = $this->context->context->functionType($strPtr, false, $i64);
        $fnFgetc = $this->context->module->addFunction('__compiler_fgetc', $fntypeFgetc);
        $this->context->registerFunction('__compiler_fgetc', $fnFgetc);
        $fntypeFgets = $this->context->context->functionType($strPtr, false, $i64, $i64);
        $fnFgets = $this->context->module->addFunction('__compiler_fgets', $fntypeFgets);
        $this->context->registerFunction('__compiler_fgets', $fnFgets);
        $fntypeFseek = $this->context->context->functionType($i64, false, $i64, $i64, $i64);
        $fnFseek = $this->context->module->addFunction('__compiler_fseek', $fntypeFseek);
        $this->context->registerFunction('__compiler_fseek', $fnFseek);
        $fntypeMkdir = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*'),
            $i64,
            $i32
        );
        $fnMkdir = $this->context->module->addFunction('__compiler_mkdir', $fntypeMkdir);
        $this->context->registerFunction('__compiler_mkdir', $fnMkdir);
        $fntypeCopy = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnCopy = $this->context->module->addFunction('__compiler_copy', $fntypeCopy);
        $this->context->registerFunction('__compiler_copy', $fnCopy);
        $fntypeMoveUploaded = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*'),
            $this->context->getTypeFromString('__string__*')
        );
        $fnMoveUploaded = $this->context->module->addFunction(
            '__compiler_move_uploaded_file',
            $fntypeMoveUploaded
        );
        $this->context->registerFunction('__compiler_move_uploaded_file', $fnMoveUploaded);
        $fntypeIsUploaded = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*')
        );
        $fnIsUploaded = $this->context->module->addFunction(
            '__compiler_is_uploaded_file',
            $fntypeIsUploaded
        );
        $this->context->registerFunction('__compiler_is_uploaded_file', $fnIsUploaded);
        $fntypeTouch = $this->context->context->functionType(
            $i32,
            false,
            $this->context->getTypeFromString('__string__*'),
            $i64
        );
        $fnTouch = $this->context->module->addFunction('__compiler_touch', $fntypeTouch);
        $this->context->registerFunction('__compiler_touch', $fnTouch);
        $void = $this->context->getTypeFromString('void');
        $fntypeRandomBytes = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $i64
        );
        $fnRandomBytes = $this->context->module->addFunction('__compiler_random_bytes', $fntypeRandomBytes);
        $this->context->registerFunction('__compiler_random_bytes', $fnRandomBytes);
        $strPtr = $this->context->getTypeFromString('__string__*');
        $i32 = $this->context->getTypeFromString('int32');
        $fntypeHash = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr, $i32);
        $fnHash = $this->context->module->addFunction('__compiler_hash', $fntypeHash);
        $this->context->registerFunction('__compiler_hash', $fnHash);
        $fntypeHashHmac = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i32);
        $fnHashHmac = $this->context->module->addFunction('__compiler_hash_hmac', $fntypeHashHmac);
        $this->context->registerFunction('__compiler_hash_hmac', $fnHashHmac);
        $fntypeHashEquals = $this->context->context->functionType($i32, false, $strPtr, $strPtr);
        $fnHashEquals = $this->context->module->addFunction('__compiler_hash_equals', $fntypeHashEquals);
        $this->context->registerFunction('__compiler_hash_equals', $fnHashEquals);
        $double = $this->context->getTypeFromString('double');
        $fntypeMicrotimeStr = $this->context->context->functionType($strPtr, false);
        $fnMicrotimeStr = $this->context->module->addFunction('__compiler_microtime_string', $fntypeMicrotimeStr);
        $this->context->registerFunction('__compiler_microtime_string', $fnMicrotimeStr);
        $fntypeMicrotimeFloat = $this->context->context->functionType($double, false);
        $fnMicrotimeFloat = $this->context->module->addFunction('__compiler_microtime_float', $fntypeMicrotimeFloat);
        $this->context->registerFunction('__compiler_microtime_float', $fnMicrotimeFloat);
        $i64 = $this->context->getTypeFromString('int64');
        $fntypePasswordHash = $this->context->context->functionType($strPtr, false, $strPtr, $i64);
        $fnPasswordHash = $this->context->module->addFunction('__compiler_password_hash', $fntypePasswordHash);
        $this->context->registerFunction('__compiler_password_hash', $fnPasswordHash);
        $fntypePasswordVerify = $this->context->context->functionType($i32, false, $strPtr, $strPtr);
        $fnPasswordVerify = $this->context->module->addFunction('__compiler_password_verify', $fntypePasswordVerify);
        $this->context->registerFunction('__compiler_password_verify', $fnPasswordVerify);
        $fntypeCrc32 = $this->context->context->functionType($i64, false, $strPtr, $i64);
        $fnCrc32 = $this->context->module->addFunction('__compiler_crc32', $fntypeCrc32);
        $this->context->registerFunction('__compiler_crc32', $fnCrc32);
        $fntypeStrtr = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr);
        $fnStrtr = $this->context->module->addFunction('__compiler_strtr', $fntypeStrtr);
        $this->context->registerFunction('__compiler_strtr', $fnStrtr);
        $fntypePregMatch = $this->context->context->functionType(
            $i64,
            false,
            $strPtr,
            $strPtr
        );
        $fnPregMatch = $this->context->module->addFunction('__compiler_preg_match', $fntypePregMatch);
        $this->context->registerFunction('__compiler_preg_match', $fnPregMatch);
        $fnPregMatchAll = $this->context->module->addFunction('__compiler_preg_match_all', $fntypePregMatch);
        $this->context->registerFunction('__compiler_preg_match_all', $fnPregMatchAll);
        $fntypePregReplace = $this->context->context->functionType(
            $strPtr,
            false,
            $strPtr,
            $strPtr,
            $strPtr
        );
        $fnPregReplace = $this->context->module->addFunction('__compiler_preg_replace', $fntypePregReplace);
        $this->context->registerFunction('__compiler_preg_replace', $fnPregReplace);
        $fntypePregLastError = $this->context->context->functionType($i64, false);
        $fnPregLastError = $this->context->module->addFunction('__compiler_preg_last_error', $fntypePregLastError);
        $this->context->registerFunction('__compiler_preg_last_error', $fnPregLastError);
        $fntypePregLastErrorMsg = $this->context->context->functionType($strPtr, false);
        $fnPregLastErrorMsg = $this->context->module->addFunction(
            '__compiler_preg_last_error_msg',
            $fntypePregLastErrorMsg
        );
        $this->context->registerFunction('__compiler_preg_last_error_msg', $fnPregLastErrorMsg);
        $fntypeSuperglobalName = $this->context->context->functionType($i64, false, $strPtr);
        $fnSuperglobalName = $this->context->module->addFunction(
            '__compiler_is_superglobal_name',
            $fntypeSuperglobalName
        );
        $this->context->registerFunction('__compiler_is_superglobal_name', $fnSuperglobalName);
        $fntypeBuiltinFunctionExists = $this->context->context->functionType($i64, false, $strPtr);
        $fnBuiltinFunctionExists = $this->context->module->addFunction(
            '__compiler_builtin_function_exists',
            $fntypeBuiltinFunctionExists
        );
        $this->context->registerFunction('__compiler_builtin_function_exists', $fnBuiltinFunctionExists);
        $fntypeFilterEmail = $this->context->context->functionType($strPtr, false, $strPtr);
        $fnFilterEmail = $this->context->module->addFunction(
            '__compiler_filter_validate_email',
            $fntypeFilterEmail
        );
        $this->context->registerFunction('__compiler_filter_validate_email', $fnFilterEmail);
        $fntypeGetrandom = $this->context->context->functionType($i64, false, $i8p, $sizeT, $i32);
        $fnGetrandom = $this->context->module->addFunction('getrandom', $fntypeGetrandom);
        $this->context->registerFunction('getrandom', $fnGetrandom);
        $fntypeExit = $this->context->context->functionType($void, false, $i32);
        $fnExit = $this->context->module->addFunction('exit', $fntypeExit);
        $this->context->registerFunction('exit', $fnExit);
        $fntypeAbort = $this->context->context->functionType($void, false);
        $fnAbort = $this->context->module->addFunction('abort', $fntypeAbort);
        $this->context->registerFunction('abort', $fnAbort);
        $fntypeFormatDt = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__string__*'),
            $i64,
            $this->context->getTypeFromString('int8')
        );
        $fnFormatDt = $this->context->module->addFunction('__compiler_format_datetime', $fntypeFormatDt);
        $this->context->registerFunction('__compiler_format_datetime', $fnFormatDt);
        $fntypeUndefKeyStr = $this->context->context->functionType(
            $void,
            false,
            $i8p,
            $sizeT
        );
        $fnUndefKeyStr = $this->context->module->addFunction(
            '__compiler_undefined_array_key_warning_cstr',
            $fntypeUndefKeyStr
        );
        $this->context->registerFunction('__compiler_undefined_array_key_warning_cstr', $fnUndefKeyStr);
        $fntypeUndefKeyLong = $this->context->context->functionType($void, false, $i64);
        $fnUndefKeyLong = $this->context->module->addFunction(
            '__compiler_undefined_array_key_warning_long',
            $fntypeUndefKeyLong
        );
        $this->context->registerFunction('__compiler_undefined_array_key_warning_long', $fnUndefKeyLong);
        $fntypeTriggerError = $this->context->context->functionType($void, false, $i8p, $sizeT, $i32);
        $fnTriggerError = $this->context->module->addFunction('__compiler_trigger_error', $fntypeTriggerError);
        $this->context->registerFunction('__compiler_trigger_error', $fnTriggerError);
        $fntypeAssertFail = $this->context->context->functionType($void, false, $i8p, $sizeT);
        $fnAssertFail = $this->context->module->addFunction('__compiler_assert_fail', $fntypeAssertFail);
        $this->context->registerFunction('__compiler_assert_fail', $fnAssertFail);
        $strPtr = $this->context->getTypeFromString('__string__*');
        $fntypeAssertFailStr = $this->context->context->functionType($void, false, $strPtr);
        $fnAssertFailStr = $this->context->module->addFunction(
            '__compiler_assert_fail_string',
            $fntypeAssertFailStr
        );
        $this->context->registerFunction('__compiler_assert_fail_string', $fnAssertFailStr);
        $i8p = $this->context->getTypeFromString('int8*');
        $i64p = $this->context->getTypeFromString('int64*');
        $libcFns = [
            'time' => [$i64, false, [$i8p]],
            'gettimeofday' => [$i32, false, [$i8p, $i8p]],
            'getpid' => [$i32, false, []],
            'localtime' => [$i8p, false, [$i64p]],
            'gmtime' => [$i8p, false, [$i64p]],
            'sleep' => [$i32, false, [$i32]],
            'usleep' => [$i32, false, [$i32]],
        ];
        foreach ($libcFns as $libcName => $spec) {
            [$ret, $vararg, $params] = $spec;
            $ft = $this->context->context->functionType($ret, $vararg, ...$params);
            $fn = $this->context->module->addFunction($libcName, $ft);
            $this->context->registerFunction($libcName, $fn);
        }
        $void = $this->context->getTypeFromString('void');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $i32 = $this->context->getTypeFromString('int32');
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
        $fntypePregSplit = $this->context->context->functionType($htPtr, false, $strPtr, $strPtr);
        $fnPregSplit = $this->context->module->addFunction('__compiler_preg_split', $fntypePregSplit);
        $this->context->registerFunction('__compiler_preg_split', $fnPregSplit);
        $i8ppPtr = $this->context->getTypeFromString('int8**');
        $i8pppPtr = $this->context->getTypeFromString('int8***');
        $fnPendingReset = $this->context->module->addFunction(
            '__phpc_pending_header_reset',
            $this->context->context->functionType($void, false)
        );
        $this->context->registerFunction('__phpc_pending_header_reset', $fnPendingReset);
        $fnPendingAdd = $this->context->module->addFunction(
            '__phpc_pending_header_add',
            $this->context->context->functionType($void, false, $strPtr, $i32)
        );
        $this->context->registerFunction('__phpc_pending_header_add', $fnPendingAdd);
        $fnPendingRemove = $this->context->module->addFunction(
            '__phpc_pending_header_remove',
            $this->context->context->functionType($void, false, $strPtr)
        );
        $this->context->registerFunction('__phpc_pending_header_remove', $fnPendingRemove);
        $fnPendingList = $this->context->module->addFunction(
            '__phpc_pending_header_list',
            $this->context->context->functionType($htPtr, false)
        );
        $this->context->registerFunction('__phpc_pending_header_list', $fnPendingList);
        $fnFnmatch = $this->context->module->addFunction(
            '__phpc_fnmatch',
            $this->context->context->functionType($i32, false, $strPtr, $strPtr, $i32)
        );
        $this->context->registerFunction('__phpc_fnmatch', $fnFnmatch);
        $fnGlobVec = $this->context->module->addFunction(
            '__phpc_glob_vec',
            $this->context->context->functionType($i32, false, $strPtr, $i32, $i8pppPtr)
        );
        $this->context->registerFunction('__phpc_glob_vec', $fnGlobVec);
        $fnScandirVec = $this->context->module->addFunction(
            '__phpc_scandir_vec',
            $this->context->context->functionType($i32, false, $strPtr, $i32, $i8pppPtr)
        );
        $this->context->registerFunction('__phpc_scandir_vec', $fnScandirVec);
        $fnStrvecFree = $this->context->module->addFunction(
            '__phpc_strvec_free',
            $this->context->context->functionType($void, false, $i8ppPtr, $i32)
        );
        $this->context->registerFunction('__phpc_strvec_free', $fnStrvecFree);
        $fnGlob = $this->context->module->addFunction(
            '__phpc_glob',
            $this->context->context->functionType($htPtr, false, $strPtr, $i32)
        );
        $this->context->registerFunction('__phpc_glob', $fnGlob);
        $fnScandir = $this->context->module->addFunction(
            '__phpc_scandir',
            $this->context->context->functionType($htPtr, false, $strPtr, $i32)
        );
        $this->context->registerFunction('__phpc_scandir', $fnScandir);
        $fnStat = $this->context->module->addFunction(
            '__phpc_stat',
            $this->context->context->functionType($htPtr, false, $strPtr, $i32)
        );
        $this->context->registerFunction('__phpc_stat', $fnStat);
        $fnFgetcsv = $this->context->module->addFunction(
            '__compiler_fgetcsv',
            $this->context->context->functionType($htPtr, false, $i64, $i64, $strPtr, $strPtr, $strPtr)
        );
        $this->context->registerFunction('__compiler_fgetcsv', $fnFgetcsv);
        $fnStrGetcsv = $this->context->module->addFunction(
            '__compiler_str_getcsv',
            $this->context->context->functionType($htPtr, false, $strPtr, $strPtr, $strPtr, $strPtr)
        );
        $this->context->registerFunction('__compiler_str_getcsv', $fnStrGetcsv);
        $valuePtr = $this->context->getTypeFromString('__value__*');
        $i64 = $this->context->getTypeFromString('int64');
        $fnParseUrl = $this->context->module->addFunction(
            '__phpc_parse_url_component',
            $this->context->context->functionType($void, false, $strPtr, $i64, $valuePtr)
        );
        $this->context->registerFunction('__phpc_parse_url_component', $fnParseUrl);
        $fnParseUrlAssoc = $this->context->module->addFunction(
            '__phpc_parse_url_assoc',
            $this->context->context->functionType($void, false, $strPtr, $valuePtr)
        );
        $this->context->registerFunction('__phpc_parse_url_assoc', $fnParseUrlAssoc);
        $fnGetdate = $this->context->module->addFunction(
            '__compiler_getdate',
            $this->context->context->functionType($void, false, $i64, $valuePtr)
        );
        $this->context->registerFunction('__compiler_getdate', $fnGetdate);
        $fnPendingFlush = $this->context->module->addFunction(
            '__phpc_response_headers_flush',
            $this->context->context->functionType($void, false)
        );
        $this->context->registerFunction('__phpc_response_headers_flush', $fnPendingFlush);
        $fnSetcookieAdd = $this->context->module->addFunction(
            '__phpc_setcookie_add',
            $this->context->context->functionType($void, false, $strPtr, $strPtr, $i64, $strPtr, $strPtr, $i32, $i32)
        );
        $this->context->registerFunction('__phpc_setcookie_add', $fnSetcookieAdd);
        $fntypeSessionApply = $this->context->context->functionType($void, false, $valuePtr);
        $fnSessionStart = $this->context->module->addFunction('__phpc_session_start_apply', $fntypeSessionApply);
        $this->context->registerFunction('__phpc_session_start_apply', $fnSessionStart);
        $fnSessionWriteClose = $this->context->module->addFunction(
            '__phpc_session_write_close_apply',
            $fntypeSessionApply
        );
        $this->context->registerFunction('__phpc_session_write_close_apply', $fnSessionWriteClose);
        $fnSessionGenerateId = $this->context->module->addFunction(
            '__phpc_session_generate_new_id',
            $this->context->context->functionType($void, false)
        );
        $this->context->registerFunction('__phpc_session_generate_new_id', $fnSessionGenerateId);
        $fnSessionRegenerate = $this->context->module->addFunction(
            '__phpc_session_regenerate_id_apply',
            $this->context->context->functionType(
                $void,
                false,
                $valuePtr,
                $this->context->getTypeFromString('int8')
            )
        );
        $this->context->registerFunction('__phpc_session_regenerate_id_apply', $fnSessionRegenerate);
        $fnSessionDestroy = $this->context->module->addFunction(
            '__phpc_session_destroy_apply',
            $fntypeSessionApply
        );
        $this->context->registerFunction('__phpc_session_destroy_apply', $fnSessionDestroy);
        SessionStart::implement($this->context);
        SessionWriteClose::implement($this->context);
        SessionRegenerateId::implement($this->context);
        SessionDestroy::implement($this->context);
        $fntypeJsonEncode = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $fnJsonEncode = $this->context->module->addFunction('__compiler_json_encode_hashtable', $fntypeJsonEncode);
        $this->context->registerFunction('__compiler_json_encode_hashtable', $fnJsonEncode);
        $fnJsonDecode = $this->context->module->addFunction(
            '__compiler_json_decode',
            $this->context->context->functionType($void, false, $strPtr, $valuePtr)
        );
        $this->context->registerFunction('__compiler_json_decode', $fnJsonDecode);
        $fntypeJsonLastError = $this->context->context->functionType($i64, false);
        $fnJsonLastError = $this->context->module->addFunction('__compiler_json_last_error', $fntypeJsonLastError);
        $this->context->registerFunction('__compiler_json_last_error', $fnJsonLastError);
        $fntypeJsonLastErrorMsg = $this->context->context->functionType($strPtr, false);
        $fnJsonLastErrorMsg = $this->context->module->addFunction(
            '__compiler_json_last_error_msg',
            $fntypeJsonLastErrorMsg
        );
        $this->context->registerFunction('__compiler_json_last_error_msg', $fnJsonLastErrorMsg);
        $fnJsonValidate = $this->context->module->addFunction(
            '__compiler_json_validate',
            $this->context->context->functionType($i64, false, $strPtr, $i64)
        );
        $this->context->registerFunction('__compiler_json_validate', $fnJsonValidate);
        $fntypeSerializeHashtable = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $fnSerializeHashtable = $this->context->module->addFunction(
            '__compiler_serialize_hashtable',
            $fntypeSerializeHashtable
        );
        $this->context->registerFunction('__compiler_serialize_hashtable', $fnSerializeHashtable);
        $fntypeSerializeValue = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $valuePtr
        );
        $fnSerializeValue = $this->context->module->addFunction(
            '__compiler_serialize_value',
            $fntypeSerializeValue
        );
        $this->context->registerFunction('__compiler_serialize_value', $fnSerializeValue);
        $fnUnserialize = $this->context->module->addFunction(
            '__compiler_unserialize',
            $this->context->context->functionType($void, false, $strPtr, $valuePtr)
        );
        $this->context->registerFunction('__compiler_unserialize', $fnUnserialize);
        $fntypeShellExec = $this->context->context->functionType($strPtr, false, $strPtr);
        $fnShellExec = $this->context->module->addFunction('__compiler_shell_exec', $fntypeShellExec);
        $this->context->registerFunction('__compiler_shell_exec', $fnShellExec);
        $fnEscapeshellarg = $this->context->module->addFunction('__compiler_escapeshellarg', $fntypeShellExec);
        $this->context->registerFunction('__compiler_escapeshellarg', $fnEscapeshellarg);
        $fntypePhpcRunCommand = $this->context->context->functionType(
            $this->context->getTypeFromString('__hashtable__*'),
            false,
            $strPtr,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $fnPhpcRunCommand = $this->context->module->addFunction('__compiler_phpc_run_command', $fntypePhpcRunCommand);
        $this->context->registerFunction('__compiler_phpc_run_command', $fnPhpcRunCommand);
        $fntypeSysGetTempDir = $this->context->context->functionType($strPtr, false);
        $fnSysGetTempDir = $this->context->module->addFunction('__compiler_sys_get_temp_dir', $fntypeSysGetTempDir);
        $this->context->registerFunction('__compiler_sys_get_temp_dir', $fnSysGetTempDir);
        $fntypeTempnam = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr);
        $fnTempnam = $this->context->module->addFunction('__compiler_tempnam', $fntypeTempnam);
        $this->context->registerFunction('__compiler_tempnam', $fnTempnam);
        $fntypeHttpBuildQuery = $this->context->context->functionType(
            $strPtr,
            false,
            $this->context->getTypeFromString('__hashtable__*'),
            $strPtr,
            $strPtr,
            $this->context->getTypeFromString('int64')
        );
        $fnHttpBuildQuery = $this->context->module->addFunction(
            '__compiler_http_build_query',
            $fntypeHttpBuildQuery
        );
        $this->context->registerFunction('__compiler_http_build_query', $fnHttpBuildQuery);
        $fntypeParseStr = $this->context->context->functionType(
            $this->context->getTypeFromString('void'),
            false,
            $this->context->getTypeFromString('__hashtable__*'),
            $strPtr
        );
        $fnParseStr = $this->context->module->addFunction('__compiler_parse_str', $fntypeParseStr);
        $this->context->registerFunction('__compiler_parse_str', $fnParseStr);
        // $this->maskedarray->register();
        // $this->nativearray->register();
    }


}
