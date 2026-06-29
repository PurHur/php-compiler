<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM CGI superglobal refresh quarantine (#5330, #9907).
 *
 * Default standalone path uses {@see SuperglobalRefreshRuntime} + {@see \PHPCompiler\Web\SuperglobalRefreshJitHelper}.
 * Opt-in via PHP_COMPILER_SUPERGLOBAL_REFRESH_LLVM=1 for inventory emit or regression compare.
 * php-src: main/php_variables.c
 */
final class SuperglobalRefreshStandaloneLlvm
{
    private const SG_MAX_BODY = 8 * 1024 * 1024;

    private const DEFAULT_SCRIPT_NAME = '/index.php';

    private const GATEWAY_INTERFACE = 'CGI/1.1';

    private const SERVER_SOFTWARE = 'PHP-Compiler-AOT';

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__superglobals__refresh');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::$blockSuffix = 0;
        $restore = self::captureInsertBlock($context);
        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);
        self::ensureParseHelpers($context);
        StringMultipartStandaloneLlvm::ensureLinked($context);

        self::implementHelper($context, '__phpc_sg_env_or_empty', self::emitEnvOrEmpty(...));
        self::implementHelper($context, '__phpc_sg_set_string_key', self::emitSetStringKey(...));
        self::implementHelper($context, '__phpc_sg_parse_form_encoded', self::emitParseFormEncoded(...));
        self::implementHelper($context, '__phpc_sg_populate_post_body', self::emitPopulatePostBody(...));
        self::implementHelper($context, '__phpc_sg_parse_cookie_header', self::emitParseCookieHeader(...));
        self::implementHelper($context, '__phpc_sg_read_request_body', self::emitReadRequestBody(...));
        self::implementHelper($context, '__phpc_sg_request_method_for', self::emitRequestMethodFor(...));
        self::implementHelper($context, '__phpc_sg_normalize_content_type', self::emitNormalizeContentType(...));
        self::implementHelper($context, '__phpc_sg_resolve_content_type', self::emitResolveContentType(...));
        self::implementHelper($context, '__phpc_sg_method_is', self::emitMethodIs(...));
        self::implementHelper($context, '__phpc_sg_should_populate_post', self::emitShouldPopulatePost(...));
        self::implementHelper($context, '__phpc_sg_is_cgi_header_env_key', self::emitIsCgiHeaderEnvKey(...));
        self::implementHelper($context, '__phpc_sg_apply_cgi_headers_from_environ', self::emitApplyCgiHeadersFromEnviron(...));
        self::implementHelper($context, '__phpc_sg_is_https_request', self::emitIsHttpsRequest(...));
        self::implementHelper($context, '__phpc_sg_parse_host_port', self::emitParseHostPort(...));
        self::implementHelper($context, '__phpc_sg_resolve_server_port', self::emitResolveServerPort(...));
        self::implementHelper($context, '__phpc_sg_apply_scheme_and_port', self::emitApplySchemeAndPort(...));
        self::implementHelper($context, '__phpc_sg_resolve_script_filename', self::emitResolveScriptFilename(...));
        self::implementHelper($context, '__phpc_sg_derive_path_info', self::emitDerivePathInfo(...));

        $fn = self::fn($context, '__superglobals__refresh', $context->context->voidType(), false);
        self::emitRefreshMain($context, $fn);

        self::restoreInsertBlock($context, $restore);
        self::registerLinkedRuntime($context);
    }

    private static function emitRefreshMain(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_refresh_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidTy = $context->getTypeFromString('void');

        $queryString = $context->builder->call(
            $context->lookupFunction('__phpc_sg_env_or_empty'),
            self::literalCstr($context, 'QUERY_STRING')
        );

        $postBodyLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $postBodyOwnedSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($sizeT->constInt(0, false), $postBodyLenSlot);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_read_request_body'),
            $postBodyOwnedSlot,
            $postBodyLenSlot
        );
        $postBodyOwned = $context->builder->load($postBodyOwnedSlot);
        $hasOwned = $context->builder->icmp(Builder::INT_NE, $postBodyOwned, $i8p->constNull());
        $emptyBody = self::literalCstr($context, '');
        $postBodyPhiBb = $fn->appendBasicBlock('sg_refresh_post_body');
        $postBodyMergeBb = $fn->appendBasicBlock('sg_refresh_post_body_merge');
        $context->builder->branchIf($hasOwned, $postBodyPhiBb, $postBodyMergeBb);
        $context->builder->positionAtEnd($postBodyPhiBb);
        $context->builder->branch($postBodyMergeBb);
        $context->builder->positionAtEnd($postBodyMergeBb);
        $postBody = $context->builder->phi($i8p);
        $postBody->addIncoming($postBodyOwned, $postBodyPhiBb);
        $postBody->addIncoming($emptyBody, $entry);

        $method = $context->builder->call(
            $context->lookupFunction('__phpc_sg_request_method_for'),
            $postBody
        );

        $contentTypeBuf = $context->builder->alloca($i8->arrayType(256), 1);
        $contentTypeBufPtr = $context->builder->pointerCast($contentTypeBuf, $i8p);
        $contentType = $context->builder->call(
            $context->lookupFunction('__phpc_sg_resolve_content_type'),
            $contentTypeBufPtr,
            $sizeT->constInt(256, false)
        );

        $populatePost = $context->builder->call(
            $context->lookupFunction('__phpc_sg_should_populate_post'),
            $method,
            $contentType,
            $postBody
        );

        $scriptNameSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store(
            $context->builder->call(
                $context->lookupFunction('__phpc_sg_env_or_empty'),
                self::literalCstr($context, 'SCRIPT_NAME')
            ),
            $scriptNameSlot
        );

        $requestUriEnv = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, 'REQUEST_URI')
        );
        $requestUriBuf = $context->builder->alloca($i8->arrayType(1024), 1);
        $requestUriBufPtr = $context->builder->pointerCast($requestUriBuf, $i8p);
        $pathInfoBuf = $context->builder->alloca($i8->arrayType(512), 1);
        $pathInfoBufPtr = $context->builder->pointerCast($pathInfoBuf, $i8p);
        $scriptFilenameBuf = $context->builder->alloca($i8->arrayType(1024), 1);
        $scriptFilenameBufPtr = $context->builder->pointerCast($scriptFilenameBuf, $i8p);

        $buildUriBb = $fn->appendBasicBlock('sg_refresh_build_uri');
        $haveUriBb = $fn->appendBasicBlock('sg_refresh_have_uri');
        $uriMergeBb = $fn->appendBasicBlock('sg_refresh_uri_merge');
        $checkUriEmptyBb = $fn->appendBasicBlock('sg_refresh_check_uri_empty');
        $uriNull = $context->builder->icmp(Builder::INT_EQ, $requestUriEnv, $i8p->constNull());
        $context->builder->branchIf($uriNull, $buildUriBb, $checkUriEmptyBb);
        $context->builder->positionAtEnd($checkUriEmptyBb);
        $uriEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($requestUriEnv),
            $i8->constInt(0, false)
        );
        $context->builder->branchIf($uriEmpty, $buildUriBb, $haveUriBb);

        $context->builder->positionAtEnd($buildUriBb);
        $scriptNameNow = $context->builder->load($scriptNameSlot);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $requestUriBufPtr,
            $sizeT->constInt(1024, false),
            self::literalCstr($context, '%s'),
            $scriptNameNow
        );
        $qs = $queryString;
        $qsNonEmpty = $context->builder->icmp(Builder::INT_NE, $context->builder->load($qs), $i8->constInt(0, false));
        $appendQsBb = $fn->appendBasicBlock('sg_refresh_append_qs');
        $afterQsBb = $fn->appendBasicBlock('sg_refresh_after_qs');
        $context->builder->branchIf($qsNonEmpty, $appendQsBb, $afterQsBb);
        $context->builder->positionAtEnd($appendQsBb);
        $used = $context->builder->call($context->lookupFunction('strlen'), $requestUriBufPtr);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $context->builder->inBoundsGEP($requestUriBufPtr, $used),
            $context->builder->sub($sizeT->constInt(1024, false), $used),
            self::literalCstr($context, '?%s'),
            $qs
        );
        $context->builder->branch($afterQsBb);
        $context->builder->positionAtEnd($afterQsBb);
        $context->builder->branch($uriMergeBb);

        $context->builder->positionAtEnd($haveUriBb);
        $context->builder->branch($uriMergeBb);

        $context->builder->positionAtEnd($uriMergeBb);
        $requestUri = $context->builder->phi($i8p);
        $requestUri->addIncoming($requestUriBufPtr, $afterQsBb);
        $requestUri->addIncoming($requestUriEnv, $haveUriBb);

        $defaultScriptBb = $fn->appendBasicBlock('sg_refresh_default_script');
        $scriptDoneBb = $fn->appendBasicBlock('sg_refresh_script_done');
        $scriptNameVal = $context->builder->load($scriptNameSlot);
        $scriptEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($scriptNameVal), $i8->constInt(0, false));
        $context->builder->branchIf($scriptEmpty, $defaultScriptBb, $scriptDoneBb);
        $context->builder->positionAtEnd($defaultScriptBb);
        $context->builder->store(self::literalCstr($context, self::DEFAULT_SCRIPT_NAME), $scriptNameSlot);
        $context->builder->branch($scriptDoneBb);
        $context->builder->positionAtEnd($scriptDoneBb);
        $scriptName = $context->builder->load($scriptNameSlot);

        $getHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($getHt, self::sgGlobalPtr($context, 'sg_GET'));
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_parse_form_encoded'),
            $getHt,
            $queryString
        );

        $filesHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($filesHt, self::sgGlobalPtr($context, 'sg_FILES'));
        $postHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($postHt, self::sgGlobalPtr($context, 'sg_POST'));
        $doPopulateBb = $fn->appendBasicBlock('sg_refresh_populate_post');
        $afterPopulateBb = $fn->appendBasicBlock('sg_refresh_after_populate');
        $shouldPopulate = $context->builder->icmp(Builder::INT_NE, $populatePost, $i32->constInt(0, false));
        $context->builder->branchIf($shouldPopulate, $doPopulateBb, $afterPopulateBb);
        $context->builder->positionAtEnd($doPopulateBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_populate_post_body'),
            $postHt,
            $contentType,
            $postBody
        );
        $context->builder->branch($afterPopulateBb);

        $context->builder->positionAtEnd($afterPopulateBb);
        $requestHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($requestHt, self::sgGlobalPtr($context, 'sg_REQUEST'));
        $qsNonEmpty2 = $context->builder->icmp(Builder::INT_NE, $context->builder->load($queryString), $i8->constInt(0, false));
        $reqQsBb = $fn->appendBasicBlock('sg_refresh_req_qs');
        $reqAfterQsBb = $fn->appendBasicBlock('sg_refresh_req_after_qs');
        $context->builder->branchIf($qsNonEmpty2, $reqQsBb, $reqAfterQsBb);
        $context->builder->positionAtEnd($reqQsBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_parse_form_encoded'),
            $requestHt,
            $queryString
        );
        $context->builder->branch($reqAfterQsBb);
        $context->builder->positionAtEnd($reqAfterQsBb);
        $reqPopulateBb = $fn->appendBasicBlock('sg_refresh_req_populate');
        $reqAfterPopulateBb = $fn->appendBasicBlock('sg_refresh_req_after_populate');
        $context->builder->branchIf($shouldPopulate, $reqPopulateBb, $reqAfterPopulateBb);
        $context->builder->positionAtEnd($reqPopulateBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_populate_post_body'),
            $requestHt,
            $contentType,
            $postBody
        );
        $context->builder->branch($reqAfterPopulateBb);

        $context->builder->positionAtEnd($reqAfterPopulateBb);
        $serverHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($serverHt, self::sgGlobalPtr($context, 'sg_SERVER'));
        self::setServerKey($context, $serverHt, 'REQUEST_METHOD', $method);
        self::setServerKey($context, $serverHt, 'QUERY_STRING', $queryString);
        self::setServerKey($context, $serverHt, 'SCRIPT_NAME', $scriptName);
        self::setServerKey($context, $serverHt, 'PHP_SELF', $scriptName);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_resolve_script_filename'),
            $scriptName,
            $scriptFilenameBufPtr,
            $sizeT->constInt(1024, false)
        );
        $scriptFnNonEmpty = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($scriptFilenameBufPtr),
            $i8->constInt(0, false)
        );
        $setScriptFnBb = $fn->appendBasicBlock('sg_refresh_script_fn');
        $afterScriptFnBb = $fn->appendBasicBlock('sg_refresh_after_script_fn');
        $context->builder->branchIf($scriptFnNonEmpty, $setScriptFnBb, $afterScriptFnBb);
        $context->builder->positionAtEnd($setScriptFnBb);
        self::setServerKey($context, $serverHt, 'SCRIPT_FILENAME', $scriptFilenameBufPtr);
        $context->builder->branch($afterScriptFnBb);
        $context->builder->positionAtEnd($afterScriptFnBb);
        self::setServerKey($context, $serverHt, 'REQUEST_URI', $requestUri);
        self::setServerKey($context, $serverHt, 'GATEWAY_INTERFACE', self::literalCstr($context, self::GATEWAY_INTERFACE));
        self::enableHeaderQueueWhenCgiEnv($context, $fn);

        $serverProtocol = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, 'SERVER_PROTOCOL')
        );
        $defaultProto = self::literalCstr($context, 'HTTP/1.1');
        $protoPhiBb = $fn->appendBasicBlock('sg_refresh_proto_merge');
        $protoDefaultBb = $fn->appendBasicBlock('sg_refresh_proto_default');
        $protoHaveBb = $fn->appendBasicBlock('sg_refresh_proto_have');
        $checkProtoEmptyBb = $fn->appendBasicBlock('sg_refresh_check_proto_empty');
        $protoNull = $context->builder->icmp(Builder::INT_EQ, $serverProtocol, $i8p->constNull());
        $context->builder->branchIf($protoNull, $protoDefaultBb, $checkProtoEmptyBb);
        $context->builder->positionAtEnd($checkProtoEmptyBb);
        $protoEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($serverProtocol),
            $i8->constInt(0, false)
        );
        $context->builder->branchIf($protoEmpty, $protoDefaultBb, $protoHaveBb);
        $context->builder->positionAtEnd($protoDefaultBb);
        $context->builder->branch($protoPhiBb);
        $context->builder->positionAtEnd($protoHaveBb);
        $context->builder->branch($protoPhiBb);
        $context->builder->positionAtEnd($protoPhiBb);
        $serverProtocolVal = $context->builder->phi($i8p);
        $serverProtocolVal->addIncoming($defaultProto, $protoDefaultBb);
        $serverProtocolVal->addIncoming($serverProtocol, $protoHaveBb);
        self::setServerKey($context, $serverHt, 'SERVER_PROTOCOL', $serverProtocolVal);
        self::setServerKey($context, $serverHt, 'SERVER_SOFTWARE', self::literalCstr($context, self::SERVER_SOFTWARE));

        self::setServerKeyIfEnvNonEmpty($context, $fn, $serverHt, 'DOCUMENT_ROOT');
        self::setServerKeyIfEnvNonEmpty($context, $fn, $serverHt, 'REMOTE_ADDR');
        self::setServerKeyIfEnvNonEmpty($context, $fn, $serverHt, 'REMOTE_PORT');

        $pathInfoEnv = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, 'PATH_INFO')
        );
        $pathInfoEnvBb = $fn->appendBasicBlock('sg_refresh_path_info_env');
        $pathInfoDeriveBb = $fn->appendBasicBlock('sg_refresh_path_info_derive');
        $pathInfoDoneBb = $fn->appendBasicBlock('sg_refresh_path_info_done');
        $checkPathInfoEmptyBb = $fn->appendBasicBlock('sg_refresh_check_path_info_empty');
        $pathInfoNull = $context->builder->icmp(Builder::INT_EQ, $pathInfoEnv, $i8p->constNull());
        $context->builder->branchIf($pathInfoNull, $pathInfoDeriveBb, $checkPathInfoEmptyBb);
        $context->builder->positionAtEnd($checkPathInfoEmptyBb);
        $pathInfoEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($pathInfoEnv),
            $i8->constInt(0, false)
        );
        $context->builder->branchIf($pathInfoEmpty, $pathInfoDeriveBb, $pathInfoEnvBb);
        $context->builder->positionAtEnd($pathInfoEnvBb);
        self::setServerKey($context, $serverHt, 'PATH_INFO', $pathInfoEnv);
        $context->builder->branch($pathInfoDoneBb);
        $context->builder->positionAtEnd($pathInfoDeriveBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_derive_path_info'),
            $scriptName,
            $requestUri,
            $pathInfoBufPtr,
            $sizeT->constInt(512, false)
        );
        $pathInfoNonEmpty = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($pathInfoBufPtr),
            $i8->constInt(0, false)
        );
        $setPathInfoBb = $fn->appendBasicBlock('sg_refresh_set_path_info');
        $skipPathInfoBb = $fn->appendBasicBlock('sg_refresh_skip_path_info');
        $context->builder->branchIf($pathInfoNonEmpty, $setPathInfoBb, $skipPathInfoBb);
        $context->builder->positionAtEnd($setPathInfoBb);
        self::setServerKey($context, $serverHt, 'PATH_INFO', $pathInfoBufPtr);
        $context->builder->branch($pathInfoDoneBb);
        $context->builder->positionAtEnd($skipPathInfoBb);
        $context->builder->branch($pathInfoDoneBb);

        $context->builder->positionAtEnd($pathInfoDoneBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_apply_cgi_headers_from_environ'),
            $serverHt
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_apply_scheme_and_port'),
            $serverHt
        );

        $cookieHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($cookieHt, self::sgGlobalPtr($context, 'sg_COOKIE'));
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_parse_cookie_header'),
            $cookieHt,
            $context->builder->call(
                $context->lookupFunction('__phpc_sg_env_or_empty'),
                self::literalCstr($context, 'HTTP_COOKIE')
            )
        );

        self::ensureSgGlobalAllocIfNull($context, $fn, 'sg_ENV');
        self::ensureSgGlobalAllocIfNull($context, $fn, 'sg_FILES');
        self::ensureSgGlobalAllocIfNull($context, $fn, 'sg_SESSION');

        $freeBodyBb = $fn->appendBasicBlock('sg_refresh_free_body');
        $exitBb = $fn->appendBasicBlock('sg_refresh_exit');
        $context->builder->branchIf($hasOwned, $freeBodyBb, $exitBb);
        $context->builder->positionAtEnd($freeBodyBb);
        $context->builder->call($context->lookupFunction('free'), $postBodyOwned);
        $context->builder->branch($exitBb);
        $context->builder->positionAtEnd($exitBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function setServerKey(Context $context, Value $serverHt, string $key, Value $value): void
    {
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_set_string_key'),
            $serverHt,
            self::literalCstr($context, $key),
            $value
        );
    }

    private static function setServerKeyIfEnvNonEmpty(
        Context $context,
        LlvmFunction $fn,
        Value $serverHt,
        string $envName
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $val = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, $envName)
        );
        $skipBb = $fn->appendBasicBlock('sg_srv_skip_'.++self::$blockSuffix);
        $setBb = $fn->appendBasicBlock('sg_srv_set_'.self::$blockSuffix);
        $doneBb = $fn->appendBasicBlock('sg_srv_done_'.self::$blockSuffix);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $val, $i8p->constNull());
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($val), $i8->constInt(0, false));
        $skip = $context->builder->or($isNull, $isEmpty);
        $context->builder->branchIf($skip, $skipBb, $setBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($setBb);
        self::setServerKey($context, $serverHt, $envName, $val);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function ensureSgGlobalAllocIfNull(Context $context, LlvmFunction $fn, string $name): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $skipBb = $fn->appendBasicBlock('sg_alloc_skip_'.++self::$blockSuffix);
        $allocBb = $fn->appendBasicBlock('sg_alloc_work_'.self::$blockSuffix);
        $doneBb = $fn->appendBasicBlock('sg_alloc_done_'.self::$blockSuffix);
        $ptr = self::sgGlobalPtr($context, $name);
        $loaded = $context->builder->load($ptr);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $loaded, $htPtr->constNull());
        $context->builder->branchIf($isNull, $allocBb, $skipBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($allocBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($ht, $ptr);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function emitEnvOrEmpty(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_env_or_empty_entry');
        $context->builder->positionAtEnd($entry);
        $i8p = $context->getTypeFromString('int8*');
        $name = $fn->getParam(0);
        $val = $context->builder->call($context->lookupFunction('getenv'), $name);
        $nullBb = $fn->appendBasicBlock('sg_env_or_empty_null');
        $okBb = $fn->appendBasicBlock('sg_env_or_empty_ok');
        $doneBb = $fn->appendBasicBlock('sg_env_or_empty_done');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $val, $i8p->constNull());
        $context->builder->branchIf($isNull, $nullBb, $okBb);
        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($okBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($i8p);
        $result->addIncoming(self::literalCstr($context, ''), $nullBb);
        $result->addIncoming($val, $okBb);
        $context->builder->returnValue($result);
    }

    private static function emitSetStringKey(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_set_key_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_str_set_string_key'),
            $fn->getParam(0),
            $fn->getParam(1),
            $fn->getParam(2)
        );
        $context->builder->returnVoid();
    }

    private static function emitParseFormEncoded(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_parse_form_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_str_parse_delimited_pairs'),
            $fn->getParam(0),
            $fn->getParam(1),
            $i8->constInt(38, false),
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();
    }

    private static function emitPopulatePostBody(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_pop_post_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $ht = $fn->getParam(0);
        $contentType = $fn->getParam(1);
        $body = $fn->getParam(2);
        $jsonBb = $fn->appendBasicBlock('sg_pop_post_json');
        $checkMultipartBb = $fn->appendBasicBlock('sg_pop_post_check_multipart');
        $multipartWork = $fn->appendBasicBlock('sg_pop_post_multipart');
        $formBb = $fn->appendBasicBlock('sg_pop_post_form');
        $doneBb = $fn->appendBasicBlock('sg_pop_post_done');
        $isJson = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('strcmp'),
                $contentType,
                self::literalCstr($context, 'application/json')
            ),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($isJson, $jsonBb, $checkMultipartBb);
        $context->builder->positionAtEnd($jsonBb);
        $context->builder->call($context->lookupFunction('__phpc_json_parse_post_body'), $ht, $body);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($checkMultipartBb);
        $isMultipart = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('strncmp'),
                $contentType,
                self::literalCstr($context, 'multipart/form-data'),
                $sizeT->constInt(19, false)
            ),
            $i32->constInt(0, false)
        );
        $context->builder->branchIf($isMultipart, $multipartWork, $formBb);
        $context->builder->positionAtEnd($multipartWork);
        $filesPtr = self::sgGlobalPtr($context, 'sg_FILES');
        $filesHt = $context->builder->load($filesPtr);
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_multipart_post'),
            $ht,
            $filesHt,
            $contentType,
            $body
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($formBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_parse_form_encoded'),
            $ht,
            $body
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitParseCookieHeader(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_cookie_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_str_parse_delimited_pairs'),
            $fn->getParam(0),
            $fn->getParam(1),
            $i8->constInt(59, false),
            $i32->constInt(1, false)
        );
        $context->builder->returnVoid();
    }

    private static function emitReadRequestBody(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_read_body_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $outOwned = $fn->getParam(0);
        $outLen = $fn->getParam(1);
        $maxBody = $sizeT->constInt(self::SG_MAX_BODY, false);
        $chunk = $sizeT->constInt(4096, false);

        $context->builder->store($sizeT->constInt(0, false), $outLen);
        $context->builder->store($i8p->constNull(), $outOwned);

        $path = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, 'REQUEST_BODY_FILE')
        );
        $fileBb = $fn->appendBasicBlock('sg_read_body_file');
        $inlineBb = $fn->appendBasicBlock('sg_read_body_inline');
        $doneBb = $fn->appendBasicBlock('sg_read_body_done');
        $pathNull = $context->builder->icmp(Builder::INT_EQ, $path, $i8p->constNull());
        $pathEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($path), $i8->constInt(0, false));
        $useInline = $context->builder->or($pathNull, $pathEmpty);
        $context->builder->branchIf($useInline, $inlineBb, $fileBb);

        $context->builder->positionAtEnd($fileBb);
        $fp = $context->builder->call(
            $context->lookupFunction('fopen'),
            $path,
            self::literalCstr($context, 'rb')
        );
        $fpFailBb = $fn->appendBasicBlock('sg_read_body_fp_fail');
        $fpOkBb = $fn->appendBasicBlock('sg_read_body_fp_ok');
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $context->builder->branchIf($fpNull, $fpFailBb, $fpOkBb);
        $context->builder->positionAtEnd($fpFailBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($fpOkBb);
        $capSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $lenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $bufSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($chunk, $capSlot);
        $context->builder->store($sizeT->constInt(0, false), $lenSlot);
        $buf = $context->builder->pointerCast(
            $context->builder->call($context->lookupFunction('malloc'), $chunk),
            $i8p
        );
        $context->builder->store($buf, $bufSlot);
        $mallocFailBb = $fn->appendBasicBlock('sg_read_body_malloc_fail');
        $readLoopBb = $fn->appendBasicBlock('sg_read_body_loop');
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $i8p->constNull());
        $context->builder->branchIf($bufNull, $mallocFailBb, $readLoopBb);
        $context->builder->positionAtEnd($mallocFailBb);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($readLoopBb);
        $growBb = $fn->appendBasicBlock('sg_read_body_grow');
        $readBb = $fn->appendBasicBlock('sg_read_body_read');
        $readDoneBb = $fn->appendBasicBlock('sg_read_body_read_done');
        $len = $context->builder->load($lenSlot);
        $cap = $context->builder->load($capSlot);
        $needGrow = $context->builder->icmp(Builder::INT_UGT, $context->builder->add($len, $chunk), $cap);
        $context->builder->branchIf($needGrow, $growBb, $readBb);

        $context->builder->positionAtEnd($growBb);
        $buf = $context->builder->load($bufSlot);
        $newCap = $context->builder->mul($cap, $sizeT->constInt(2, false));
        $tooBig = $context->builder->icmp(Builder::INT_UGT, $newCap, $context->builder->add($maxBody, $sizeT->constInt(1, false)));
        $growFailBb = $fn->appendBasicBlock('sg_read_body_grow_fail');
        $growOkBb = $fn->appendBasicBlock('sg_read_body_grow_ok');
        $context->builder->branchIf($tooBig, $growFailBb, $growOkBb);
        $context->builder->positionAtEnd($growFailBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($growOkBb);
        $grown = $context->builder->call(
            $context->lookupFunction('realloc'),
            $context->bytePtr($buf),
            $newCap
        );
        $reallocFailBb = $fn->appendBasicBlock('sg_read_body_realloc_fail');
        $reallocOkBb = $fn->appendBasicBlock('sg_read_body_realloc_ok');
        $grownNull = $context->builder->icmp(Builder::INT_EQ, $grown, $i8p->constNull());
        $context->builder->branchIf($grownNull, $reallocFailBb, $reallocOkBb);
        $context->builder->positionAtEnd($reallocFailBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($reallocOkBb);
        $context->builder->store($grown, $bufSlot);
        $context->builder->store($newCap, $capSlot);
        $context->builder->branch($readBb);

        $context->builder->positionAtEnd($readBb);
        $buf = $context->builder->load($bufSlot);
        $len = $context->builder->load($lenSlot);
        $n = $context->builder->call(
            $context->lookupFunction('fread'),
            $context->builder->inBoundsGEP($buf, $len),
            $sizeT->constInt(1, false),
            $chunk,
            $fp
        );
        $zeroRead = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $afterReadBb = $fn->appendBasicBlock('sg_read_body_after_read');
        $context->builder->branchIf($zeroRead, $readDoneBb, $afterReadBb);
        $context->builder->positionAtEnd($afterReadBb);
        $newLen = $context->builder->add($len, $n);
        $context->builder->store($newLen, $lenSlot);
        $overMax = $context->builder->icmp(Builder::INT_UGT, $newLen, $maxBody);
        $overMaxBb = $fn->appendBasicBlock('sg_read_body_over_max');
        $context->builder->branchIf($overMax, $overMaxBb, $readLoopBb);
        $context->builder->positionAtEnd($overMaxBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($readDoneBb);
        $buf = $context->builder->load($bufSlot);
        $len = $context->builder->load($lenSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $len));
        $context->builder->store($len, $outLen);
        $context->builder->store($buf, $outOwned);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($inlineBb);
        $inlineBody = $context->builder->call(
            $context->lookupFunction('__phpc_sg_env_or_empty'),
            self::literalCstr($context, 'REQUEST_BODY')
        );
        $inlineEmptyBb = $fn->appendBasicBlock('sg_read_body_inline_empty');
        $inlineCopyBb = $fn->appendBasicBlock('sg_read_body_inline_copy');
        $inlineLen = $context->builder->call($context->lookupFunction('strlen'), $inlineBody);
        $inlineEmpty = $context->builder->icmp(Builder::INT_EQ, $inlineLen, $sizeT->constInt(0, false));
        $context->builder->branchIf($inlineEmpty, $inlineEmptyBb, $inlineCopyBb);
        $context->builder->positionAtEnd($inlineEmptyBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($inlineCopyBb);
        $copy = $context->builder->pointerCast(
            $context->builder->call(
                $context->lookupFunction('malloc'),
                $context->builder->add($inlineLen, $sizeT->constInt(1, false))
            ),
            $i8p
        );
        $copyFailBb = $fn->appendBasicBlock('sg_read_body_copy_fail');
        $copyOkBb = $fn->appendBasicBlock('sg_read_body_copy_ok');
        $copyNull = $context->builder->icmp(Builder::INT_EQ, $copy, $i8p->constNull());
        $context->builder->branchIf($copyNull, $copyFailBb, $copyOkBb);
        $context->builder->positionAtEnd($copyFailBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($copyOkBb);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $copy,
            $inlineBody,
            $context->builder->add($inlineLen, $sizeT->constInt(1, false))
        );
        $context->builder->store($inlineLen, $outLen);
        $context->builder->store($copy, $outOwned);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitRequestMethodFor(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_method_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $postBody = $fn->getParam(0);
        $method = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, 'REQUEST_METHOD')
        );
        $haveBb = $fn->appendBasicBlock('sg_method_have');
        $inferBb = $fn->appendBasicBlock('sg_method_infer');
        $doneBb = $fn->appendBasicBlock('sg_method_done');
        $methodNull = $context->builder->icmp(Builder::INT_EQ, $method, $i8p->constNull());
        $methodEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($method), $i8->constInt(0, false));
        $infer = $context->builder->or($methodNull, $methodEmpty);
        $context->builder->branchIf($infer, $inferBb, $haveBb);
        $context->builder->positionAtEnd($haveBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($inferBb);
        $hasBody = $context->builder->icmp(Builder::INT_NE, $context->builder->load($postBody), $i8->constInt(0, false));
        $postBb = $fn->appendBasicBlock('sg_method_post');
        $getBb = $fn->appendBasicBlock('sg_method_get');
        $context->builder->branchIf($hasBody, $postBb, $getBb);
        $context->builder->positionAtEnd($postBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($getBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($i8p);
        $result->addIncoming($method, $haveBb);
        $result->addIncoming(self::literalCstr($context, 'POST'), $postBb);
        $result->addIncoming(self::literalCstr($context, 'GET'), $getBb);
        $context->builder->returnValue($result);
    }

    private static function emitNormalizeContentType(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_norm_ct_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $raw = $fn->getParam(0);
        $out = $fn->getParam(1);
        $outLen = $fn->getParam(2);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $endSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $nullBb = $fn->appendBasicBlock('sg_norm_ct_null');
        $workBb = $fn->appendBasicBlock('sg_norm_ct_work');
        $doneBb = $fn->appendBasicBlock('sg_norm_ct_done');
        $rawNull = $context->builder->icmp(Builder::INT_EQ, $raw, $i8p->constNull());
        $context->builder->branchIf($rawNull, $nullBb, $workBb);
        $context->builder->positionAtEnd($nullBb);
        $context->builder->store($i8->constInt(0, false), $out);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($workBb);
        $context->builder->call(
            $context->lookupFunction('strncpy'),
            $out,
            $raw,
            $context->builder->sub($outLen, $sizeT->constInt(1, false))
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($out, $context->builder->sub($outLen, $sizeT->constInt(1, false))));
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);
        $lowerHead = $fn->appendBasicBlock('sg_norm_ct_lower_head');
        $lowerBody = $fn->appendBasicBlock('sg_norm_ct_lower_body');
        $semiBb = $fn->appendBasicBlock('sg_norm_ct_semi');
        $context->builder->branch($lowerHead);
        $context->builder->positionAtEnd($lowerHead);
        $idx = $context->builder->load($idxSlot);
        $ch = $context->builder->load($context->builder->inBoundsGEP($out, $idx));
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $context->builder->branchIf($atEnd, $semiBb, $lowerBody);
        $context->builder->positionAtEnd($lowerBody);
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(90, false))
        );
        $lowerBb = $fn->appendBasicBlock('sg_norm_ct_do_lower');
        $lowerSkipBb = $fn->appendBasicBlock('sg_norm_ct_lower_skip');
        $context->builder->branchIf($isUpper, $lowerBb, $lowerSkipBb);
        $context->builder->positionAtEnd($lowerBb);
        $context->builder->store(
            $context->builder->trunc(
                $context->builder->sub($context->builder->zExt($ch, $i64), $i64->constInt(65 - 97, false)),
                $i8
            ),
            $context->builder->inBoundsGEP($out, $idx)
        );
        $context->builder->branch($lowerSkipBb);
        $context->builder->positionAtEnd($lowerSkipBb);
        $context->builder->store($context->builder->add($idx, $sizeT->constInt(1, false)), $idxSlot);
        $context->builder->branch($lowerHead);
        $context->builder->positionAtEnd($semiBb);
        $end = $context->builder->call($context->lookupFunction('strlen'), $out);
        $context->builder->store($end, $endSlot);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $semiHead = $fn->appendBasicBlock('sg_norm_ct_semi_head');
        $semiBody = $fn->appendBasicBlock('sg_norm_ct_semi_body');
        $semiDone = $fn->appendBasicBlock('sg_norm_ct_semi_done');
        $context->builder->branch($semiHead);
        $context->builder->positionAtEnd($semiHead);
        $i = $context->builder->load($iSlot);
        $endVal = $context->builder->load($endSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $i, $endVal);
        $context->builder->branchIf($pastEnd, $doneBb, $semiBody);
        $context->builder->positionAtEnd($semiBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($out, $i));
        $isSemi = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(59, false));
        $trimBb = $fn->appendBasicBlock('sg_norm_ct_trim');
        $semiCont = $fn->appendBasicBlock('sg_norm_ct_semi_cont');
        $context->builder->branchIf($isSemi, $trimBb, $semiCont);
        $context->builder->positionAtEnd($trimBb);
        $trimHead = $fn->appendBasicBlock('sg_norm_ct_trim_head');
        $trimBody = $fn->appendBasicBlock('sg_norm_ct_trim_body');
        $trimDone = $fn->appendBasicBlock('sg_norm_ct_trim_done');
        $context->builder->branch($trimHead);
        $context->builder->positionAtEnd($trimHead);
        $endVal = $context->builder->load($endSlot);
        $iVal = $context->builder->load($iSlot);
        $canTrim = $context->builder->icmp(Builder::INT_SGT, $endVal, $context->builder->add($iVal, $sizeT->constInt(1, false)));
        $trail = $context->builder->load($context->builder->inBoundsGEP($out, $context->builder->sub($endVal, $sizeT->constInt(1, false))));
        $isSpace = $context->builder->icmp(Builder::INT_EQ, $trail, $i8->constInt(32, false));
        $isTab = $context->builder->icmp(Builder::INT_EQ, $trail, $i8->constInt(9, false));
        $isWs = $context->builder->or($isSpace, $isTab);
        $doTrim = $context->builder->and($canTrim, $isWs);
        $context->builder->branchIf($doTrim, $trimBody, $trimDone);
        $context->builder->positionAtEnd($trimBody);
        $context->builder->store($context->builder->sub($endVal, $sizeT->constInt(1, false)), $endSlot);
        $context->builder->branch($trimHead);
        $context->builder->positionAtEnd($trimDone);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($out, $context->builder->load($iSlot)));
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($semiCont);
        $context->builder->store($context->builder->add($i, $sizeT->constInt(1, false)), $iSlot);
        $context->builder->branch($semiHead);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitResolveContentType(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_resolve_ct_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $buf = $fn->getParam(0);
        $bufLen = $fn->getParam(1);
        $ctPrimary = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, 'CONTENT_TYPE')
        );
        $tryHttpBb = $fn->appendBasicBlock('sg_resolve_ct_http');
        $haveBb = $fn->appendBasicBlock('sg_resolve_ct_have');
        $nullBb = $fn->appendBasicBlock('sg_resolve_ct_null');
        $doneBb = $fn->appendBasicBlock('sg_resolve_ct_done');
        $ctNull = $context->builder->icmp(Builder::INT_EQ, $ctPrimary, $i8p->constNull());
        $ctEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($ctPrimary), $i8->constInt(0, false));
        $tryHttp = $context->builder->or($ctNull, $ctEmpty);
        $context->builder->branchIf($tryHttp, $tryHttpBb, $haveBb);
        $context->builder->positionAtEnd($tryHttpBb);
        $ctHttp = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, 'HTTP_CONTENT_TYPE')
        );
        $ctNull2 = $context->builder->icmp(Builder::INT_EQ, $ctHttp, $i8p->constNull());
        $context->builder->branchIf($ctNull2, $nullBb, $haveBb);
        $context->builder->positionAtEnd($nullBb);
        $context->builder->store($i8->constInt(0, false), $buf);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($haveBb);
        $ct = $context->builder->phi($i8p);
        $ct->addIncoming($ctPrimary, $entry);
        $ct->addIncoming($ctHttp, $tryHttpBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_normalize_content_type'),
            $ct,
            $buf,
            $bufLen
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($buf);
    }

    private static function emitMethodIs(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_method_is_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $method = $fn->getParam(0);
        $name = $fn->getParam(1);
        $failBb = $fn->appendBasicBlock('sg_method_is_fail');
        $loopHead = $fn->appendBasicBlock('sg_method_is_head');
        $loopBody = $fn->appendBasicBlock('sg_method_is_body');
        $okBb = $fn->appendBasicBlock('sg_method_is_ok');
        $methodNull = $context->builder->icmp(Builder::INT_EQ, $method, $i8p->constNull());
        $context->builder->branchIf($methodNull, $failBb, $loopHead);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $a = $context->builder->load($context->builder->inBoundsGEP($method, $idx));
        $b = $context->builder->load($context->builder->inBoundsGEP($name, $idx));
        $aEnd = $context->builder->icmp(Builder::INT_EQ, $a, $i8->constInt(0, false));
        $bEnd = $context->builder->icmp(Builder::INT_EQ, $b, $i8->constInt(0, false));
        $bothEnd = $context->builder->and($aEnd, $bEnd);
        $eitherEnd = $context->builder->or($aEnd, $bEnd);
        $oneEndedBb = $fn->appendBasicBlock('sg_method_is_one_end');
        $context->builder->branchIf($bothEnd, $okBb, $oneEndedBb);
        $context->builder->positionAtEnd($oneEndedBb);
        $context->builder->branchIf($eitherEnd, $failBb, $loopBody);
        $context->builder->positionAtEnd($loopBody);
        $aUp = self::toupperChar($context, $a);
        $bUp = self::toupperChar($context, $b);
        $neq = $context->builder->icmp(Builder::INT_NE, $aUp, $bUp);
        $neqBb = $fn->appendBasicBlock('sg_method_is_neq');
        $contBb = $fn->appendBasicBlock('sg_method_is_cont');
        $context->builder->branchIf($neq, $neqBb, $contBb);
        $context->builder->positionAtEnd($neqBb);
        $context->builder->branch($failBb);
        $context->builder->positionAtEnd($contBb);
        $context->builder->store($context->builder->add($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($i32->constInt(1, false));
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function emitShouldPopulatePost(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_should_post_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $method = $fn->getParam(0);
        $contentType = $fn->getParam(1);
        $postBody = $fn->getParam(2);
        $noBb = $fn->appendBasicBlock('sg_should_post_no');
        $checkMethodBb = $fn->appendBasicBlock('sg_should_post_check');
        $emptyBody = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($postBody), $i8->constInt(0, false));
        $context->builder->branchIf($emptyBody, $noBb, $checkMethodBb);
        $context->builder->positionAtEnd($noBb);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->builder->positionAtEnd($checkMethodBb);
        $isPut = $context->builder->call($context->lookupFunction('__phpc_sg_method_is'), $method, self::literalCstr($context, 'PUT'));
        $isPatch = $context->builder->call($context->lookupFunction('__phpc_sg_method_is'), $method, self::literalCstr($context, 'PATCH'));
        $isDelete = $context->builder->call($context->lookupFunction('__phpc_sg_method_is'), $method, self::literalCstr($context, 'DELETE'));
        $isVerb = $context->builder->or($isPut, $context->builder->or($isPatch, $isDelete));
        $verbBb = $fn->appendBasicBlock('sg_should_post_verb');
        $postBb = $fn->appendBasicBlock('sg_should_post_post');
        $context->builder->branchIf($context->i32Success($isVerb), $verbBb, $postBb);
        $context->builder->positionAtEnd($verbBb);
        $isForm = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('strcmp'),
                $contentType,
                self::literalCstr($context, 'application/x-www-form-urlencoded')
            ),
            $i32->constInt(0, false)
        );
        $context->builder->returnValue($context->builder->zext($isForm, $i32));
        $context->builder->positionAtEnd($postBb);
        $isPost = $context->builder->call($context->lookupFunction('__phpc_sg_method_is'), $method, self::literalCstr($context, 'POST'));
        $notPostBb = $fn->appendBasicBlock('sg_should_post_not_post');
        $postWorkBb = $fn->appendBasicBlock('sg_should_post_post_work');
        $context->builder->branchIf($context->i32Success($isPost), $postWorkBb, $notPostBb);
        $context->builder->positionAtEnd($notPostBb);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->builder->positionAtEnd($postWorkBb);
        $ctEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($contentType), $i8->constInt(0, false));
        $yesBb = $fn->appendBasicBlock('sg_should_post_yes');
        $ctCheckBb = $fn->appendBasicBlock('sg_should_post_ct');
        $context->builder->branchIf($ctEmpty, $yesBb, $ctCheckBb);
        $context->builder->positionAtEnd($yesBb);
        $context->builder->returnValue($i32->constInt(1, false));
        $context->builder->positionAtEnd($ctCheckBb);
        $isForm = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcmp'), $contentType, self::literalCstr($context, 'application/x-www-form-urlencoded')),
            $i32->constInt(0, false)
        );
        $isMultipart = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strncmp'), $contentType, self::literalCstr($context, 'multipart/form-data'), $sizeT->constInt(19, false)),
            $i32->constInt(0, false)
        );
        $isJson = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcmp'), $contentType, self::literalCstr($context, 'application/json')),
            $i32->constInt(0, false)
        );
        $ok = $context->builder->or($isForm, $context->builder->or($isMultipart, $isJson));
        $context->builder->returnValue($context->builder->zext($ok, $i32));
    }

    private static function emitIsCgiHeaderEnvKey(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_cgi_key_entry');
        $context->builder->positionAtEnd($entry);
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $key = $fn->getParam(0);
        $isHttp = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strncmp'), $key, self::literalCstr($context, 'HTTP_'), $sizeT->constInt(5, false)),
            $i32->constInt(0, false)
        );
        $checkCtBb = $fn->appendBasicBlock('sg_cgi_key_ct');
        $yesBb = $fn->appendBasicBlock('sg_cgi_key_yes');
        $context->builder->branchIf($isHttp, $yesBb, $checkCtBb);
        $context->builder->positionAtEnd($checkCtBb);
        $isCt = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcmp'), $key, self::literalCstr($context, 'CONTENT_TYPE')),
            $i32->constInt(0, false)
        );
        $checkLenBb = $fn->appendBasicBlock('sg_cgi_key_len');
        $context->builder->branchIf($isCt, $yesBb, $checkLenBb);
        $context->builder->positionAtEnd($checkLenBb);
        $isCl = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcmp'), $key, self::literalCstr($context, 'CONTENT_LENGTH')),
            $i32->constInt(0, false)
        );
        $noBb = $fn->appendBasicBlock('sg_cgi_key_no');
        $doneBb = $fn->appendBasicBlock('sg_cgi_key_done');
        $context->builder->branchIf($isCl, $yesBb, $noBb);
        $context->builder->positionAtEnd($yesBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($noBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($i32);
        $result->addIncoming($i32->constInt(1, false), $yesBb);
        $result->addIncoming($i32->constInt(0, false), $noBb);
        $context->builder->returnValue($result);
    }

    private static function emitApplyCgiHeadersFromEnviron(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_cgi_hdr_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $sizeT = $context->getTypeFromString('size_t');
        $server = $fn->getParam(0);
        $environGlobal = $context->module->getNamedGlobal('environ');
        if (null === $environGlobal) {
            $context->builder->returnVoid();

            return;
        }
        $envPtr = $context->builder->load(
            $context->builder->pointerCast($environGlobal, $i8pp->pointerType(0))
        );
        $envSlot = BasicBlockHelper::entryAlloca($context, $i8pp);
        $context->builder->store($envPtr, $envSlot);
        $keyBuf = $context->builder->alloca($i8->arrayType(256), 1);
        $keyBufPtr = $context->builder->pointerCast($keyBuf, $i8p);
        $loopHead = $fn->appendBasicBlock('sg_cgi_hdr_head');
        $loopBody = $fn->appendBasicBlock('sg_cgi_hdr_body');
        $doneBb = $fn->appendBasicBlock('sg_cgi_hdr_done');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $env = $context->builder->load($envSlot);
        $envNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8pp->constNull());
        $entryNull = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($env), $i8p->constNull());
        $stop = $context->builder->or($envNull, $entryNull);
        $context->builder->branchIf($stop, $doneBb, $loopBody);
        $context->builder->positionAtEnd($loopBody);
        $line = $context->builder->load($env);
        $eq = $context->builder->call($context->lookupFunction('strchr'), $line, $i32->constInt(61, false));
        $noEqBb = $fn->appendBasicBlock('sg_cgi_hdr_no_eq');
        $haveEqBb = $fn->appendBasicBlock('sg_cgi_hdr_have_eq');
        $eqNull = $context->builder->icmp(Builder::INT_EQ, $eq, $i8p->constNull());
        $context->builder->branchIf($eqNull, $noEqBb, $haveEqBb);
        $context->builder->positionAtEnd($haveEqBb);
        $keyLen = $context->builder->sub(
            $context->builder->ptrToInt($eq, $i64),
            $context->builder->ptrToInt($line, $i64)
        );
        $tooLong = $context->builder->icmp(Builder::INT_UGE, $keyLen, $sizeT->constInt(256, false));
        $skipLongBb = $fn->appendBasicBlock('sg_cgi_hdr_skip_long');
        $copyKeyBb = $fn->appendBasicBlock('sg_cgi_hdr_copy_key');
        $context->builder->branchIf($tooLong, $skipLongBb, $copyKeyBb);
        $context->builder->positionAtEnd($copyKeyBb);
        $context->builder->call($context->lookupFunction('memcpy'), $keyBufPtr, $line, $keyLen);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($keyBufPtr, $keyLen));
        $isHdr = $context->builder->call($context->lookupFunction('__phpc_sg_is_cgi_header_env_key'), $keyBufPtr);
        $setBb = $fn->appendBasicBlock('sg_cgi_hdr_set');
        $afterSetBb = $fn->appendBasicBlock('sg_cgi_hdr_after_set');
        $isHdrBool = $context->builder->icmp(Builder::INT_NE, $isHdr, $i32->constInt(0, false));
        $context->builder->branchIf($isHdrBool, $setBb, $afterSetBb);
        $context->builder->positionAtEnd($setBb);
        $value = $context->builder->inBoundsGEP($eq, $i64->constInt(1, false));
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_set_string_key'),
            $server,
            $keyBufPtr,
            $value
        );
        $context->builder->branch($afterSetBb);
        $context->builder->positionAtEnd($skipLongBb);
        $context->builder->branch($afterSetBb);
        $context->builder->positionAtEnd($afterSetBb);
        $context->builder->branch($noEqBb);
        $context->builder->positionAtEnd($noEqBb);
        $context->builder->store(
            $context->builder->inBoundsGEP($env, $i64->constInt(1, false)),
            $envSlot
        );
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitIsHttpsRequest(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_https_entry');
        $checkHttpsBb = $fn->appendBasicBlock('sg_https_check_val');
        $cmpZeroBb = $fn->appendBasicBlock('sg_https_cmp_zero');
        $cmpOffBb = $fn->appendBasicBlock('sg_https_cmp_off');
        $checkProtoBb = $fn->appendBasicBlock('sg_https_proto');
        $protoCmpBb = $fn->appendBasicBlock('sg_https_proto_cmp');
        $yesBb = $fn->appendBasicBlock('sg_https_yes');
        $noBb = $fn->appendBasicBlock('sg_https_no');
        $doneBb = $fn->appendBasicBlock('sg_https_done');

        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $one = $i32->constInt(1, false);
        $zero = $i32->constInt(0, false);

        $https = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'HTTPS'));
        $httpsNull = $context->builder->icmp(Builder::INT_EQ, $https, $i8p->constNull());
        $context->builder->branchIf($httpsNull, $checkProtoBb, $checkHttpsBb);

        $context->builder->positionAtEnd($checkHttpsBb);
        $httpsEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($https), $i8->constInt(0, false));
        $context->builder->branchIf($httpsEmpty, $checkProtoBb, $cmpZeroBb);

        $context->builder->positionAtEnd($cmpZeroBb);
        $isZero = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcmp'), $https, self::literalCstr($context, '0')),
            $zero
        );
        $context->builder->branchIf($isZero, $checkProtoBb, $cmpOffBb);

        $context->builder->positionAtEnd($cmpOffBb);
        $isOff = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcasecmp'), $https, self::literalCstr($context, 'off')),
            $zero
        );
        $context->builder->branchIf($isOff, $checkProtoBb, $yesBb);

        $context->builder->positionAtEnd($checkProtoBb);
        $proto = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'HTTP_X_FORWARDED_PROTO'));
        $protoNull = $context->builder->icmp(Builder::INT_EQ, $proto, $i8p->constNull());
        $context->builder->branchIf($protoNull, $noBb, $protoCmpBb);

        $context->builder->positionAtEnd($protoCmpBb);
        $protoHttps = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call($context->lookupFunction('strcasecmp'), $proto, self::literalCstr($context, 'https')),
            $zero
        );
        $context->builder->branchIf($protoHttps, $yesBb, $noBb);

        $context->builder->positionAtEnd($yesBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($noBb);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($i32);
        $result->addIncoming($one, $yesBb);
        $result->addIncoming($zero, $noBb);
        $context->builder->returnValue($result);
    }

    private static function emitParseHostPort(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_host_port_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $host = $fn->getParam(0);
        $nameOut = $fn->getParam(1);
        $nameLen = $fn->getParam(2);
        $portOut = $fn->getParam(3);
        $failBb = $fn->appendBasicBlock('sg_host_port_fail');
        $okBb = $fn->appendBasicBlock('sg_host_port_ok');
        $workBb = $fn->appendBasicBlock('sg_host_port_work');
        $context->builder->store($i8->constInt(0, false), $nameOut);
        $context->builder->store($i32->constInt(0, false), $portOut);
        $hostEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($host), $i8->constInt(0, false));
        $context->builder->branchIf($hostEmpty, $failBb, $workBb);
        $context->builder->positionAtEnd($workBb);
        $isBracket = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($host), $i8->constInt(91, false));
        $bracketBb = $fn->appendBasicBlock('sg_host_port_bracket');
        $plainBb = $fn->appendBasicBlock('sg_host_port_plain');
        $context->builder->branchIf($isBracket, $bracketBb, $plainBb);
        $context->builder->positionAtEnd($bracketBb);
        $close = $context->builder->call($context->lookupFunction('strchr'), $host, $i32->constInt(93, false));
        $noCloseBb = $fn->appendBasicBlock('sg_host_port_no_close');
        $haveCloseBb = $fn->appendBasicBlock('sg_host_port_have_close');
        $closeNull = $context->builder->icmp(Builder::INT_EQ, $close, $i8p->constNull());
        $context->builder->branchIf($closeNull, $noCloseBb, $haveCloseBb);
        $context->builder->positionAtEnd($noCloseBb);
        $context->builder->branch($plainBb);
        $context->builder->positionAtEnd($haveCloseBb);
        $namePart = $context->builder->sub(
            $context->builder->ptrToInt($close, $i64),
            $context->builder->add($context->builder->ptrToInt($host, $i64), $i64->constInt(1, false))
        );
        $maxName = $context->builder->sub($context->builder->zExt($nameLen, $i64), $i64->constInt(1, false));
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGE, $namePart, $maxName),
            $maxName,
            $namePart
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $nameOut,
            $context->builder->inBoundsGEP($host, $i64->constInt(1, false)),
            $context->builder->trunc($copyLen, $sizeT)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($nameOut, $copyLen));
        $closeCh = $context->builder->load($close);
        $nextCh = $context->builder->load($context->builder->inBoundsGEP($close, $i64->constInt(1, false)));
        $hasPort = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $closeCh, $i8->constInt(93, false)),
            $context->builder->icmp(Builder::INT_EQ, $nextCh, $i8->constInt(58, false))
        );
        $setPortBb = $fn->appendBasicBlock('sg_host_port_set_port');
        $bracketOkBb = $fn->appendBasicBlock('sg_host_port_bracket_ok');
        $context->builder->branchIf($hasPort, $setPortBb, $bracketOkBb);
        $context->builder->positionAtEnd($setPortBb);
        $port = $context->builder->call(
            $context->lookupFunction('atoi'),
            $context->builder->inBoundsGEP($close, $i64->constInt(2, false))
        );
        $context->builder->store($port, $portOut);
        $context->builder->branch($okBb);
        $context->builder->positionAtEnd($bracketOkBb);
        $context->builder->branch($okBb);
        $context->builder->positionAtEnd($plainBb);
        $colon = $context->builder->call($context->lookupFunction('strrchr'), $host, $i32->constInt(58, false));
        $noColonBb = $fn->appendBasicBlock('sg_host_port_no_colon');
        $haveColonBb = $fn->appendBasicBlock('sg_host_port_have_colon');
        $colonNull = $context->builder->icmp(Builder::INT_EQ, $colon, $i8p->constNull());
        $context->builder->branchIf($colonNull, $noColonBb, $haveColonBb);
        $context->builder->positionAtEnd($haveColonBb);
        $afterColon = $context->builder->call(
            $context->lookupFunction('strchr'),
            $context->builder->inBoundsGEP($colon, $i64->constInt(1, false)),
            $i32->constInt(58, false)
        );
        $ipv6Bb = $fn->appendBasicBlock('sg_host_port_ipv6');
        $portParseBb = $fn->appendBasicBlock('sg_host_port_port_parse');
        $afterNotNull = $context->builder->icmp(Builder::INT_NE, $afterColon, $i8p->constNull());
        $context->builder->branchIf($afterNotNull, $ipv6Bb, $portParseBb);
        $context->builder->positionAtEnd($portParseBb);
        $port = $context->builder->call($context->lookupFunction('atoi'), $context->builder->inBoundsGEP($colon, $i64->constInt(1, false)));
        $portPos = $context->builder->icmp(Builder::INT_SGT, $port, $i32->constInt(0, false));
        $copyHostBb = $fn->appendBasicBlock('sg_host_port_copy_host');
        $plainOkBb = $fn->appendBasicBlock('sg_host_port_plain_ok');
        $context->builder->branchIf($portPos, $copyHostBb, $plainOkBb);
        $context->builder->positionAtEnd($copyHostBb);
        $namePart = $context->builder->sub(
            $context->builder->ptrToInt($colon, $i64),
            $context->builder->ptrToInt($host, $i64)
        );
        $maxName = $context->builder->sub($context->builder->zExt($nameLen, $i64), $i64->constInt(1, false));
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGE, $namePart, $maxName),
            $maxName,
            $namePart
        );
        $context->builder->call($context->lookupFunction('memcpy'), $nameOut, $host, $context->builder->trunc($copyLen, $sizeT));
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($nameOut, $copyLen));
        $context->builder->store($port, $portOut);
        $context->builder->branch($okBb);
        $context->builder->positionAtEnd($ipv6Bb);
        $context->builder->branch($noColonBb);
        $context->builder->positionAtEnd($plainOkBb);
        $context->builder->branch($okBb);
        $context->builder->positionAtEnd($noColonBb);
        $context->builder->call(
            $context->lookupFunction('strncpy'),
            $nameOut,
            $host,
            $context->builder->sub($nameLen, $sizeT->constInt(1, false))
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($nameOut, $context->builder->sub($nameLen, $sizeT->constInt(1, false))));
        $context->builder->branch($okBb);
        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($i32->constInt(1, false));
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function emitResolveServerPort(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_srv_port_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $https = $fn->getParam(0);
        $portFromHost = $fn->getParam(1);
        $fromEnv = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'SERVER_PORT'));
        $useHostBb = $fn->appendBasicBlock('sg_srv_port_host');
        $defaultBb = $fn->appendBasicBlock('sg_srv_port_default');
        $doneBb = $fn->appendBasicBlock('sg_srv_port_done');
        $envBb = $fn->appendBasicBlock('sg_srv_port_env');
        $envNull = $context->builder->icmp(Builder::INT_EQ, $fromEnv, $i8p->constNull());
        $envEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($fromEnv), $i8->constInt(0, false));
        $skipEnv = $context->builder->or($envNull, $envEmpty);
        $context->builder->branchIf($skipEnv, $useHostBb, $envBb);
        $context->builder->positionAtEnd($envBb);
        $port = $context->builder->call($context->lookupFunction('atoi'), $fromEnv);
        $portPos = $context->builder->icmp(Builder::INT_SGT, $port, $i32->constInt(0, false));
        $context->builder->branchIf($portPos, $doneBb, $useHostBb);
        $context->builder->positionAtEnd($useHostBb);
        $hostPos = $context->builder->icmp(Builder::INT_SGT, $portFromHost, $i32->constInt(0, false));
        $context->builder->branchIf($hostPos, $doneBb, $defaultBb);
        $context->builder->positionAtEnd($defaultBb);
        $isHttps = $context->builder->icmp(Builder::INT_NE, $https, $i32->constInt(0, false));
        $defaultPort = $context->builder->select($isHttps, $i32->constInt(443, false), $i32->constInt(80, false));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($i32);
        $result->addIncoming($port, $envBb);
        $result->addIncoming($portFromHost, $useHostBb);
        $result->addIncoming($defaultPort, $defaultBb);
        $context->builder->returnValue($result);
    }

    private static function emitApplySchemeAndPort(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_scheme_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $server = $fn->getParam(0);
        $host = $context->builder->call(
            $context->lookupFunction('__phpc_sg_env_or_empty'),
            self::literalCstr($context, 'HTTP_HOST')
        );
        $https = $context->builder->call($context->lookupFunction('__phpc_sg_is_https_request'));
        $scheme = $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $https, $i32->constInt(0, false)),
            self::literalCstr($context, 'https'),
            self::literalCstr($context, 'http')
        );
        $serverNameBuf = $context->builder->alloca($i8->arrayType(256), 1);
        $serverNamePtr = $context->builder->pointerCast($serverNameBuf, $context->getTypeFromString('int8*'));
        $portFromHostSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($i32->constInt(0, false), $portFromHostSlot);
        $hostNonEmpty = $context->builder->icmp(Builder::INT_NE, $context->builder->load($host), $i8->constInt(0, false));
        $hostWorkBb = $fn->appendBasicBlock('sg_scheme_host');
        $afterHostBb = $fn->appendBasicBlock('sg_scheme_after_host');
        $context->builder->branchIf($hostNonEmpty, $hostWorkBb, $afterHostBb);
        $context->builder->positionAtEnd($hostWorkBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_set_string_key'),
            $server,
            self::literalCstr($context, 'HTTP_HOST'),
            $host
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_parse_host_port'),
            $host,
            $serverNamePtr,
            $sizeT->constInt(256, false),
            $portFromHostSlot
        );
        $nameNonEmpty = $context->builder->icmp(Builder::INT_NE, $context->builder->load($serverNamePtr), $i8->constInt(0, false));
        $setNameBb = $fn->appendBasicBlock('sg_scheme_set_name');
        $context->builder->branchIf($nameNonEmpty, $setNameBb, $afterHostBb);
        $context->builder->positionAtEnd($setNameBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_set_string_key'),
            $server,
            self::literalCstr($context, 'SERVER_NAME'),
            $serverNamePtr
        );
        $context->builder->branch($afterHostBb);
        $context->builder->positionAtEnd($afterHostBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_set_string_key'),
            $server,
            self::literalCstr($context, 'REQUEST_SCHEME'),
            $scheme
        );
        $isHttpsBool = $context->builder->icmp(Builder::INT_NE, $https, $i32->constInt(0, false));
        $setHttpsBb = $fn->appendBasicBlock('sg_scheme_https');
        $portBb = $fn->appendBasicBlock('sg_scheme_port');
        $context->builder->branchIf($isHttpsBool, $setHttpsBb, $portBb);
        $context->builder->positionAtEnd($setHttpsBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_set_string_key'),
            $server,
            self::literalCstr($context, 'HTTPS'),
            self::literalCstr($context, 'on')
        );
        $context->builder->branch($portBb);
        $context->builder->positionAtEnd($portBb);
        $portBuf = $context->builder->alloca($i8->arrayType(16), 1);
        $portBufPtr = $context->builder->pointerCast($portBuf, $context->getTypeFromString('int8*'));
        $port = $context->builder->call(
            $context->lookupFunction('__phpc_sg_resolve_server_port'),
            $https,
            $context->builder->load($portFromHostSlot)
        );
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $portBufPtr,
            $sizeT->constInt(16, false),
            self::literalCstr($context, '%d'),
            $port
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_sg_set_string_key'),
            $server,
            self::literalCstr($context, 'SERVER_PORT'),
            $portBufPtr
        );
        $context->builder->returnVoid();
    }

    private static function emitResolveScriptFilename(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_script_fn_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $scriptName = $fn->getParam(0);
        $out = $fn->getParam(1);
        $outLen = $fn->getParam(2);
        $context->builder->store($i8->constInt(0, false), $out);
        $fromEnv = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'SCRIPT_FILENAME'));
        $useEnvBb = $fn->appendBasicBlock('sg_script_fn_env');
        $deriveBb = $fn->appendBasicBlock('sg_script_fn_derive');
        $doneBb = $fn->appendBasicBlock('sg_script_fn_done');
        $envNull = $context->builder->icmp(Builder::INT_EQ, $fromEnv, $i8p->constNull());
        $envEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($fromEnv), $i8->constInt(0, false));
        $useDerive = $context->builder->or($envNull, $envEmpty);
        $context->builder->branchIf($useDerive, $deriveBb, $useEnvBb);
        $context->builder->positionAtEnd($useEnvBb);
        $context->builder->call(
            $context->lookupFunction('strncpy'),
            $out,
            $fromEnv,
            $context->builder->sub($outLen, $sizeT->constInt(1, false))
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($out, $context->builder->sub($outLen, $sizeT->constInt(1, false))));
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($deriveBb);
        $documentRoot = $context->builder->call($context->lookupFunction('getenv'), self::literalCstr($context, 'DOCUMENT_ROOT'));
        $rootNull = $context->builder->icmp(Builder::INT_EQ, $documentRoot, $i8p->constNull());
        $rootEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($documentRoot), $i8->constInt(0, false));
        $scriptNull = $context->builder->icmp(Builder::INT_EQ, $scriptName, $i8p->constNull());
        $scriptEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($scriptName), $i8->constInt(0, false));
        $skip = $context->builder->or($rootNull, $context->builder->or($rootEmpty, $context->builder->or($scriptNull, $scriptEmpty)));
        $buildBb = $fn->appendBasicBlock('sg_script_fn_build');
        $context->builder->branchIf($skip, $doneBb, $buildBb);
        $context->builder->positionAtEnd($buildBb);
        $rootLen = $context->builder->call($context->lookupFunction('strlen'), $documentRoot);
        $rootLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($rootLen, $rootLenSlot);
        $trimHead = $fn->appendBasicBlock('sg_script_fn_trim_head');
        $trimBody = $fn->appendBasicBlock('sg_script_fn_trim_body');
        $trimDone = $fn->appendBasicBlock('sg_script_fn_trim_done');
        $context->builder->branch($trimHead);
        $context->builder->positionAtEnd($trimHead);
        $len = $context->builder->load($rootLenSlot);
        $canTrim = $context->builder->icmp(Builder::INT_UGT, $len, $sizeT->constInt(0, false));
        $last = $context->builder->load($context->builder->inBoundsGEP($documentRoot, $context->builder->sub($len, $sizeT->constInt(1, false))));
        $isSlash = $context->builder->icmp(Builder::INT_EQ, $last, $i8->constInt(47, false));
        $doTrim = $context->builder->and($canTrim, $isSlash);
        $context->builder->branchIf($doTrim, $trimBody, $trimDone);
        $context->builder->positionAtEnd($trimBody);
        $context->builder->store($context->builder->sub($len, $sizeT->constInt(1, false)), $rootLenSlot);
        $context->builder->branch($trimHead);
        $context->builder->positionAtEnd($trimDone);
        $rootLen = $context->builder->load($rootLenSlot);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $out,
            $outLen,
            self::literalCstr($context, '%.*s%s'),
            $context->builder->trunc($rootLen, $context->getTypeFromString('int32')),
            $documentRoot,
            $scriptName
        );
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitDerivePathInfo(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_path_info_entry');
        $context->builder->positionAtEnd($entry);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $scriptName = $fn->getParam(0);
        $requestUri = $fn->getParam(1);
        $out = $fn->getParam(2);
        $outLen = $fn->getParam(3);
        $pathBuf = $context->builder->alloca($i8->arrayType(1024), 1);
        $pathBufPtr = $context->builder->pointerCast($pathBuf, $i8p);
        $doneBb = $fn->appendBasicBlock('sg_path_info_done');
        $context->builder->store($i8->constInt(0, false), $out);
        $scriptEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($scriptName), $i8->constInt(0, false));
        $uriEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($requestUri), $i8->constInt(0, false));
        $early = $context->builder->or($scriptEmpty, $uriEmpty);
        $workBb = $fn->appendBasicBlock('sg_path_info_work');
        $context->builder->branchIf($early, $doneBb, $workBb);
        $context->builder->positionAtEnd($workBb);
        $q = $context->builder->call($context->lookupFunction('strchr'), $requestUri, $i32->constInt(63, false));
        $useBufBb = $fn->appendBasicBlock('sg_path_info_use_buf');
        $cmpBb = $fn->appendBasicBlock('sg_path_info_cmp');
        $qNull = $context->builder->icmp(Builder::INT_EQ, $q, $i8p->constNull());
        $context->builder->branchIf($qNull, $cmpBb, $useBufBb);
        $context->builder->positionAtEnd($useBufBb);
        $pathLen = $context->builder->sub(
            $context->builder->ptrToInt($q, $i64),
            $context->builder->ptrToInt($requestUri, $i64)
        );
        $maxLen = $sizeT->constInt(1023, false);
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGE, $pathLen, $context->builder->zExt($maxLen, $i64)),
            $maxLen,
            $context->builder->trunc($pathLen, $sizeT)
        );
        $context->builder->call($context->lookupFunction('memcpy'), $pathBufPtr, $requestUri, $copyLen);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($pathBufPtr, $copyLen));
        $context->builder->branch($cmpBb);
        $context->builder->positionAtEnd($cmpBb);
        $path = $context->builder->phi($i8p);
        $path->addIncoming($requestUri, $workBb);
        $path->addIncoming($pathBufPtr, $useBufBb);
        $scriptLen = $context->builder->call($context->lookupFunction('strlen'), $scriptName);
        $prefixOk = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->call(
                $context->lookupFunction('strncmp'),
                $path,
                $scriptName,
                $scriptLen
            ),
            $i32->constInt(0, false)
        );
        $copyOutBb = $fn->appendBasicBlock('sg_path_info_copy');
        $context->builder->branchIf($prefixOk, $copyOutBb, $doneBb);
        $context->builder->positionAtEnd($copyOutBb);
        $context->builder->call(
            $context->lookupFunction('strncpy'),
            $out,
            $context->builder->inBoundsGEP($path, $scriptLen),
            $context->builder->sub($outLen, $sizeT->constInt(1, false))
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($out, $context->builder->sub($outLen, $sizeT->constInt(1, false))));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function toupperChar(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(122, false))
        );

        return $context->builder->select(
            $isLower,
            $context->builder->trunc(
                $context->builder->sub($context->builder->zExt($ch, $i64), $i64->constInt(97 - 65, false)),
                $i8
            ),
            $ch
        );
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementHelper(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }
        $fn = self::declareHelperFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareHelperFunction(Context $context, string $name): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            return $probe;
        }
        $fn = $context->module->addFunction($name, self::helperSignature($context, $name));
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function helperSignature(Context $context, string $name)
    {
        $voidTy = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        return match ($name) {
            '__phpc_sg_env_or_empty' => $context->context->functionType($i8p, false, $i8p),
            '__phpc_sg_request_method_for' => $context->context->functionType($i8p, false, $i8p),
            '__phpc_sg_resolve_content_type' => $context->context->functionType($i8p, false, $i8p, $sizeT),
            '__phpc_sg_method_is' => $context->context->functionType($i32, false, $i8p, $i8p),
            '__phpc_sg_should_populate_post' => $context->context->functionType($i32, false, $i8p, $i8p, $i8p),
            '__phpc_sg_is_cgi_header_env_key' => $context->context->functionType($i32, false, $i8p),
            '__phpc_sg_is_https_request' => $context->context->functionType($i32, false),
            '__phpc_sg_parse_host_port' => $context->context->functionType($i32, false, $i8p, $i8p, $sizeT, $i32->pointerType(0)),
            '__phpc_sg_resolve_server_port' => $context->context->functionType($i32, false, $i32, $i32),
            '__phpc_sg_set_string_key' => $context->context->functionType($voidTy, false, $htPtr, $i8p, $i8p),
            '__phpc_sg_parse_form_encoded', '__phpc_sg_parse_cookie_header' => $context->context->functionType($voidTy, false, $htPtr, $i8p),
            '__phpc_sg_populate_post_body' => $context->context->functionType($voidTy, false, $htPtr, $i8p, $i8p),
            '__phpc_sg_apply_cgi_headers_from_environ', '__phpc_sg_apply_scheme_and_port' => $context->context->functionType($voidTy, false, $htPtr),
            '__phpc_sg_read_request_body' => $context->context->functionType($voidTy, false, $i8p->pointerType(0), $sizeT->pointerType(0)),
            '__phpc_sg_normalize_content_type' => $context->context->functionType($voidTy, false, $i8p, $i8p, $sizeT),
            '__phpc_sg_resolve_script_filename' => $context->context->functionType($voidTy, false, $i8p, $i8p, $sizeT),
            '__phpc_sg_derive_path_info' => $context->context->functionType($voidTy, false, $i8p, $i8p, $i8p, $sizeT),
            default => throw new \LogicException('Unknown superglobal refresh helper: '.$name),
        };
    }

    private static function ensureGlobals(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        foreach (['sg_GET', 'sg_POST', 'sg_REQUEST', 'sg_SERVER', 'sg_COOKIE', 'sg_ENV', 'sg_FILES', 'sg_SESSION'] as $name) {
            if (null === $context->module->getNamedGlobal($name)) {
                $g = $context->module->addGlobal($htPtr, $name);
                $g->setInitializer($htPtr->constNull());
            }
        }
        if (null === $context->module->getNamedGlobal('environ')) {
            $context->module->addGlobal($context->getTypeFromString('int8**'), 'environ');
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        foreach (
            [
                ['getenv', $i8p, false, [$charPtr]],
                ['strlen', $sizeT, false, [$i8p]],
                ['strcmp', $i32, false, [$i8p, $i8p]],
                ['strncmp', $i32, false, [$i8p, $i8p, $sizeT]],
                ['strcasecmp', $i32, false, [$i8p, $i8p]],
                ['strchr', $i8p, false, [$i8p, $i32]],
                ['strrchr', $i8p, false, [$i8p, $i32]],
                ['atoi', $i32, false, [$i8p]],
                ['strncpy', $charPtr, false, [$charPtr, $charPtr, $sizeT]],
                ['memcpy', $voidPtr, false, [$voidPtr, $voidPtr, $sizeT]],
                ['malloc', $voidPtr, false, [$sizeT]],
                ['realloc', $voidPtr, false, [$voidPtr, $sizeT]],
                ['free', $voidTy, false, [$voidPtr]],
                ['fopen', $i8p, false, [$i8p, $i8p]],
                ['fread', $sizeT, false, [$i8p, $sizeT, $sizeT, $i8p]],
                ['fclose', $i32, false, [$i8p]],
            ] as [$name, $ret, $vararg, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, $vararg, ...$params));
        }
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::ensureExternal($context, '__hashtable__alloc', $context->context->functionType($htPtr, false));
    }

    private static function ensureParseHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidTy = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        foreach (
            [
                ['__phpc_parse_str_parse_delimited_pairs', $voidTy, [$htPtr, $i8p, $i8, $i32]],
                ['__phpc_parse_str_set_string_key', $voidTy, [$htPtr, $i8p, $i8p]],
                ['__phpc_json_parse_post_body', $voidTy, [$htPtr, $i8p]],
                ['__phpc_parse_multipart_post', $voidTy, [$htPtr, $htPtr, $i8p, $i8p]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function sgGlobalPtr(Context $context, string $name): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('SuperglobalRefreshStandaloneLlvm global missing: '.$name);
        }
        $htPtr = $context->getTypeFromString('__hashtable__*');

        return $context->builder->pointerCast($global, $htPtr->pointerType(0));
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($text),
            $context->getTypeFromString('char*')
        );
    }

    private static function fn(Context $context, string $name, $ret, bool $vararg, ...$params): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            return $probe;
        }
        $ft = $context->context->functionType($ret, $vararg, ...$params);
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }

    /**
     * Standalone AOT/JIT refresh always queues header() for deferred flush (#634).
     *
     * headers_list() still mirrors php-src CLI vs CGI via GATEWAY_INTERFACE in
     * {@see PendingHeadersRuntime::implementList} (#4037).
     */
    private static function enableHeaderQueueWhenCgiEnv(Context $context, LlvmFunction $fn): void
    {
        $context->builder->call($context->lookupFunction('__phpc_header_queue_enable'));
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__superglobals__refresh');
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException('__superglobals__refresh missing after SuperglobalRefreshStandaloneLlvm LLVM implement');
        }
        $context->registerFunction('__superglobals__refresh', $fn);
    }
}
