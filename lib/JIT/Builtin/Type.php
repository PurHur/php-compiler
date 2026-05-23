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
        $i1 = $this->context->getTypeFromString('int1');
        $fntypeHash = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr, $i1);
        $fnHash = $this->context->module->addFunction('__compiler_hash', $fntypeHash);
        $this->context->registerFunction('__compiler_hash', $fnHash);
        $fntypeHashHmac = $this->context->context->functionType($strPtr, false, $strPtr, $strPtr, $strPtr, $i1);
        $fnHashHmac = $this->context->module->addFunction('__compiler_hash_hmac', $fntypeHashHmac);
        $this->context->registerFunction('__compiler_hash_hmac', $fnHashHmac);
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
        $fntypeSuperglobalName = $this->context->context->functionType($i64, false, $strPtr);
        $fnSuperglobalName = $this->context->module->addFunction(
            '__compiler_is_superglobal_name',
            $fntypeSuperglobalName
        );
        $this->context->registerFunction('__compiler_is_superglobal_name', $fnSuperglobalName);
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
        $fntypeTriggerError = $this->context->context->functionType(
            $void,
            false,
            $i8p,
            $sizeT,
            $i32
        );
        $fnTriggerError = $this->context->module->addFunction(
            '__phpc_trigger_error_cstr',
            $fntypeTriggerError
        );
        $this->context->registerFunction('__phpc_trigger_error_cstr', $fnTriggerError);
        $i8p = $this->context->getTypeFromString('int8*');
        $i64p = $this->context->getTypeFromString('int64*');
        $libcFns = [
            'time' => [$i64, false, [$i8p]],
            'localtime' => [$i8p, false, [$i64p]],
            'gmtime' => [$i8p, false, [$i64p]],
        ];
        foreach ($libcFns as $libcName => $spec) {
            [$ret, $vararg, $params] = $spec;
            $ft = $this->context->context->functionType($ret, $vararg, ...$params);
            $fn = $this->context->module->addFunction($libcName, $ft);
            $this->context->registerFunction($libcName, $fn);
        }
        $this->hashtable->register();
        $void = $this->context->getTypeFromString('void');
        $strPtr = $this->context->getTypeFromString('__string__*');
        $i32 = $this->context->getTypeFromString('int32');
        $htPtr = $this->context->getTypeFromString('__hashtable__*');
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
        $valuePtr = $this->context->getTypeFromString('__value__*');
        $i64 = $this->context->getTypeFromString('int64');
        $fnParseUrl = $this->context->module->addFunction(
            '__phpc_parse_url_component',
            $this->context->context->functionType($void, false, $strPtr, $i64, $valuePtr)
        );
        $this->context->registerFunction('__phpc_parse_url_component', $fnParseUrl);
        $fnPendingFlush = $this->context->module->addFunction(
            '__phpc_response_headers_flush',
            $this->context->context->functionType($void, false)
        );
        $this->context->registerFunction('__phpc_response_headers_flush', $fnPendingFlush);
        $fntypeJsonEncode = $this->context->context->functionType(
            $this->context->getTypeFromString('__string__*'),
            false,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $fnJsonEncode = $this->context->module->addFunction('__compiler_json_encode_hashtable', $fntypeJsonEncode);
        $this->context->registerFunction('__compiler_json_encode_hashtable', $fnJsonEncode);
        $fntypeShellExec = $this->context->context->functionType($strPtr, false, $strPtr);
        $fnShellExec = $this->context->module->addFunction('__compiler_shell_exec', $fntypeShellExec);
        $this->context->registerFunction('__compiler_shell_exec', $fnShellExec);
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
        // $this->maskedarray->register();
        // $this->nativearray->register();
    }


}
