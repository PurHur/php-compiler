<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\EnvironMirrorRuntime;
use PHPCompiler\JIT\Builtin\MultipartRuntime;
use PHPCompiler\JIT\Builtin\ParseStrRuntime;
use PHPCompiler\JIT\Builtin\PendingHeadersRuntime;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * User-script standalone AOT: native LLVM CGI superglobal refresh (#13717, #19512).
 *
 * Nested {@see SuperglobalRefreshJitHelper} JIT during init segfaults (#13571); the PHP
 * bridge returns VM {@see __object__*} handles that cannot populate native sg_* (#12039).
 * Process environ mirror routes through {@see EnvironMirrorRuntime} + {@see EnvironMirrorNativeJitHelper} (#18984).
 * Form/cookie parsing routes through {@see ParseStrRuntime} + {@see __compiler_parse_str}
 * ({@see ParseStrJitHelper::parseIntoNative} streaming materializer — #13900).
 * Housed in ext/standard (not lib/JIT/Builtin) — same kernel-move pattern as #19500 / #19466.
 * php-src: main/php_variables.c
 */
final class JitSuperglobalRefreshKernel
{
    private const DEFAULT_SCRIPT_NAME = '/index.php';

    private const GATEWAY_INTERFACE = 'CGI/1.1';

    private const SERVER_SOFTWARE = 'PHP-Compiler-AOT';

    public static function implement(Context $context): void
    {
        self::ensurePrerequisites($context);
        self::emitRefresh($context);
    }

    /** ParseStr/environ/globals before user main LLVM — nested JIT must not run after user lowering (#15417). */
    public static function ensurePrerequisites(Context $context): void
    {
        LibcExtern::register($context);
        StringGetenv::ensureLibcGetenv($context);
        self::ensureGlobals($context);
        ParseStrRuntime::ensureUserScriptLinked($context);
        MultipartRuntime::ensureUserScriptLinked($context);
        EnvironMirrorRuntime::ensureLinked($context);
        self::ensureHeaderQueueExternal($context);
    }

    /** Emit __superglobals__refresh native LLVM once prerequisites are linked (#15417). */
    public static function emitRefresh(Context $context): void
    {
        if ($context->isThinStandaloneAotMain()) {
            // Re-link parse_str/cookie bridges after user-script lowering — nested helper
            // compile can replace the v8 bridge with the embed ParseStrJitHelper body (#18832).
            self::ensureEmitRefreshPrerequisites($context);
        }

        $probe = $context->module->getNamedFunction('__superglobals__refresh');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            if ($context->isThinStandaloneAotMain() && !self::isUserScriptRefresh($probe)) {
                self::clearFunctionBody($probe);
            } else {
                $context->registerFunction('__superglobals__refresh', $probe);

                return;
            }
        }

        $restore = self::captureInsertBlock($context);
        unset($restore);
        $fn = self::declareRefresh($context);
        $context->builder->clearInsertionPosition();
        self::emitRefreshMain($context, $fn);

        $context->registerFunction('__superglobals__refresh', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareRefresh(Context $context): LlvmFunction
    {
        $probe = $context->module->getNamedFunction('__superglobals__refresh');
        if (null !== $probe) {
            return $probe;
        }

        $fn = $context->module->addFunction(
            '__superglobals__refresh',
            $context->context->functionType($context->context->voidType(), false)
        );
        $context->registerFunction('__superglobals__refresh', $fn);

        return $fn;
    }

    private static function emitRefreshMain(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('sg_user_refresh_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');

        try {
            $context->builder->call($context->lookupFunction('__phpc_header_queue_enable'));
        } catch (\Throwable) {
        }

        $queryCstr = self::storeLibcGetenvInEntry($context, $entry, 'QUERY_STRING');
        $postBodyCstr = self::storeLibcGetenvInEntry($context, $entry, 'REQUEST_BODY');
        $contentTypeCstr = self::storeLibcGetenvInEntry($context, $entry, 'CONTENT_TYPE');
        $methodCstr = self::storeLibcGetenvInEntry($context, $entry, 'REQUEST_METHOD');
        $scriptNameCstr = self::storeLibcGetenvOrDefaultInEntry($context, $entry, 'SCRIPT_NAME', self::DEFAULT_SCRIPT_NAME);
        $requestUriCstr = self::storeLibcGetenvInEntry($context, $entry, 'REQUEST_URI');
        $pathInfoCstr = self::storeLibcGetenvInEntry($context, $entry, 'PATH_INFO');
        $serverProtocolCstr = self::storeLibcGetenvOrDefaultInEntry($context, $entry, 'SERVER_PROTOCOL', 'HTTP/1.1');
        $cookieCstr = self::storeLibcGetenvInEntry($context, $entry, 'HTTP_COOKIE');
        $documentRootCstr = self::storeLibcGetenvInEntry($context, $entry, 'DOCUMENT_ROOT');
        $remoteAddrCstr = self::storeLibcGetenvInEntry($context, $entry, 'REMOTE_ADDR');
        $remotePortCstr = self::storeLibcGetenvInEntry($context, $entry, 'REMOTE_PORT');
        $httpHostCstr = self::storeLibcGetenvInEntry($context, $entry, 'HTTP_HOST');
        $methodResolved = self::storeResolvedMethodInEntry($context, $entry, $methodCstr, $postBodyCstr);
        $requestUriResolved = self::storeResolvedRequestUriInEntry($context, $entry, $requestUriCstr, $scriptNameCstr);
        // PATH_INFO from environ, else derive URI suffix after SCRIPT_NAME (#20923).
        $pathInfoResolved = self::storeResolvedPathInfoInEntry(
            $context,
            $entry,
            $pathInfoCstr,
            $scriptNameCstr,
            $requestUriResolved
        );

        $workBb = $fn->appendBasicBlock('sg_user_refresh_work');
        $context->builder->branch($workBb);
        $context->builder->positionAtEnd($workBb);

        $getHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($getHt, self::sgGlobalPtr($context, 'sg_GET'));
        self::parseFormEncodedFromCstrSlot($context, $getHt, $queryCstr);

        $postHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $filesHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($postHt, self::sgGlobalPtr($context, 'sg_POST'));
        $context->builder->store($filesHt, self::sgGlobalPtr($context, 'sg_FILES'));

        $postBodyEmpty = self::isCstrSlotEmpty($context, $postBodyCstr);
        $populatePostBb = $fn->appendBasicBlock('sg_user_refresh_populate_post');
        $afterPostBb = $fn->appendBasicBlock('sg_user_refresh_after_post');
        $context->builder->branchIf($postBodyEmpty, $afterPostBb, $populatePostBb);
        $context->builder->positionAtEnd($populatePostBb);
        self::populatePostBodyFromCstrSlot($context, $postHt, $filesHt, $contentTypeCstr, $postBodyCstr);
        $context->builder->branch($afterPostBb);
        $context->builder->positionAtEnd($afterPostBb);

        $requestHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($requestHt, self::sgGlobalPtr($context, 'sg_REQUEST'));
        $queryNonEmpty = $context->builder->not(self::isCstrSlotEmpty($context, $queryCstr));
        $reqQsBb = $fn->appendBasicBlock('sg_user_refresh_req_qs');
        $reqAfterQsBb = $fn->appendBasicBlock('sg_user_refresh_req_after_qs');
        $context->builder->branchIf($queryNonEmpty, $reqQsBb, $reqAfterQsBb);
        $context->builder->positionAtEnd($reqQsBb);
        self::parseFormEncodedFromCstrSlot($context, $requestHt, $queryCstr);
        $context->builder->branch($reqAfterQsBb);
        $context->builder->positionAtEnd($reqAfterQsBb);
        $reqPostBb = $fn->appendBasicBlock('sg_user_refresh_req_post');
        $reqDoneBb = $fn->appendBasicBlock('sg_user_refresh_req_done');
        $context->builder->branchIf($postBodyEmpty, $reqDoneBb, $reqPostBb);
        $context->builder->positionAtEnd($reqPostBb);
        self::parseFormEncodedFromCstrSlot($context, $requestHt, $postBodyCstr);
        $context->builder->branch($reqDoneBb);
        $context->builder->positionAtEnd($reqDoneBb);

        $serverHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($serverHt, self::sgGlobalPtr($context, 'sg_SERVER'));
        self::fillServerFromProcessEnviron($context, $fn, $serverHt);
        self::setServerKeyFromCstr($context, $serverHt, 'QUERY_STRING', $queryCstr);
        self::setServerKeyFromCstr($context, $serverHt, 'SCRIPT_NAME', $scriptNameCstr);
        self::setServerKeyFromCstr($context, $serverHt, 'PHP_SELF', $scriptNameCstr);
        self::setServerKeyFromCstrIfNonEmpty($context, $fn, $serverHt, 'REQUEST_METHOD', $methodResolved);
        self::setServerKeyFromCstr($context, $serverHt, 'REQUEST_URI', $requestUriResolved);
        self::setServerKeyFromCstr($context, $serverHt, 'PATH_INFO', $pathInfoResolved);
        self::setServerKeyFromLiteral($context, $serverHt, 'GATEWAY_INTERFACE', self::GATEWAY_INTERFACE);
        self::setServerKeyFromLiteral($context, $serverHt, 'SERVER_SOFTWARE', self::SERVER_SOFTWARE);
        self::setServerKeyFromCstr($context, $serverHt, 'SERVER_PROTOCOL', $serverProtocolCstr);

        foreach (
            [
                'DOCUMENT_ROOT' => $documentRootCstr,
                'REMOTE_ADDR' => $remoteAddrCstr,
                'REMOTE_PORT' => $remotePortCstr,
                'HTTP_HOST' => $httpHostCstr,
            ] as $key => $slot
        ) {
            $empty = self::isCstrSlotEmpty($context, $slot);
            $skipBb = $fn->appendBasicBlock('sg_user_refresh_skip_'.$key);
            $setBb = $fn->appendBasicBlock('sg_user_refresh_set_'.$key);
            $nextBb = $fn->appendBasicBlock('sg_user_refresh_after_'.$key);
            $context->builder->branchIf($empty, $skipBb, $setBb);
            $context->builder->positionAtEnd($setBb);
            self::setServerKeyFromCstr($context, $serverHt, $key, $slot);
            $context->builder->branch($nextBb);
            $context->builder->positionAtEnd($skipBb);
            $context->builder->branch($nextBb);
            $context->builder->positionAtEnd($nextBb);
        }

        $cookieHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($cookieHt, self::sgGlobalPtr($context, 'sg_COOKIE'));
        $cookieEmpty = self::isCstrSlotEmpty($context, $cookieCstr);
        $cookieParseBb = $fn->appendBasicBlock('sg_user_refresh_cookie_parse');
        $cookieDoneBb = $fn->appendBasicBlock('sg_user_refresh_cookie_done');
        $context->builder->branchIf($cookieEmpty, $cookieDoneBb, $cookieParseBb);
        $context->builder->positionAtEnd($cookieParseBb);
        self::parseCookieFromCstrSlot($context, $cookieHt, $cookieCstr);
        $context->builder->branch($cookieDoneBb);
        $context->builder->positionAtEnd($cookieDoneBb);

        foreach (['sg_ENV', 'sg_SESSION'] as $globalName) {
            $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
            $context->builder->store($ht, self::sgGlobalPtr($context, $globalName));
        }

        $context->builder->returnVoid();
    }

    private static function storeLibcGetenvInEntry(Context $context, \PHPLLVM\BasicBlock $entry, string $name): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $slot = self::entryAllocaInBlock($context, $entry, $i8p);
        $env = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, $name)
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8p->constNull());
        $context->builder->store(
            $context->builder->select($isNull, self::literalCstr($context, ''), $env),
            $slot
        );

        return $slot;
    }

    private static function storeLibcGetenvOrDefaultInEntry(
        Context $context,
        \PHPLLVM\BasicBlock $entry,
        string $name,
        string $default
    ): Value {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $slot = self::entryAllocaInBlock($context, $entry, $i8p);
        $env = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, $name)
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8p->constNull());
        $emptyWhenNull = $context->builder->select($isNull, self::literalCstr($context, ''), $env);
        $useDefault = $context->builder->or($isNull, self::isCstrEmpty($context, $emptyWhenNull));
        $context->builder->store(
            $context->builder->select($useDefault, self::literalCstr($context, $default), $emptyWhenNull),
            $slot
        );

        return $slot;
    }

    private static function storeResolvedMethodInEntry(
        Context $context,
        \PHPLLVM\BasicBlock $entry,
        Value $methodSlot,
        Value $postBodySlot
    ): Value {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $outSlot = self::entryAllocaInBlock($context, $entry, $i8p);
        $methodEmpty = self::isCstrSlotEmpty($context, $methodSlot);
        $postEmpty = self::isCstrSlotEmpty($context, $postBodySlot);
        $usePost = $context->builder->and($methodEmpty, $context->builder->not($postEmpty));
        $resolved = $context->builder->select(
            $methodEmpty,
            $context->builder->select($usePost, self::literalCstr($context, 'POST'), self::literalCstr($context, '')),
            $context->builder->load($methodSlot)
        );
        $context->builder->store($resolved, $outSlot);

        return $outSlot;
    }

    private static function storeResolvedRequestUriInEntry(
        Context $context,
        \PHPLLVM\BasicBlock $entry,
        Value $requestUriSlot,
        Value $scriptNameSlot
    ): Value {
        $i8p = $context->getTypeFromString('int8*');
        $outSlot = self::entryAllocaInBlock($context, $entry, $i8p);
        $uriEmpty = self::isCstrSlotEmpty($context, $requestUriSlot);
        $context->builder->store(
            $context->builder->select($uriEmpty, $context->builder->load($scriptNameSlot), $context->builder->load($requestUriSlot)),
            $outSlot
        );

        return $outSlot;
    }

    /**
     * PATH_INFO from environ, else REQUEST_URI path suffix after SCRIPT_NAME (#20923).
     *
     * Uses selects only (no extra basic blocks) so it can run in the refresh entry block
     * alongside other getenv slots. GEP is only consumed when URI is longer than SCRIPT_NAME.
     *
     * php-src / VM SSOT: {@see \PHPCompiler\Web\Superglobals::derivePathInfo()}
     */
    private static function storeResolvedPathInfoInEntry(
        Context $context,
        \PHPLLVM\BasicBlock $entry,
        Value $pathInfoSlot,
        Value $scriptNameSlot,
        Value $requestUriSlot
    ): Value {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $outSlot = self::entryAllocaInBlock($context, $entry, $i8p);

        $fromEnv = $context->builder->load($pathInfoSlot);
        $envEmpty = self::isCstrEmpty($context, $fromEnv);

        $script = $context->builder->load($scriptNameSlot);
        $uri = $context->builder->load($requestUriSlot);
        $empty = self::literalCstr($context, '');
        $scriptLen = $context->builder->call($context->lookupFunction('strlen'), $script);
        $uriLen = $context->builder->call($context->lookupFunction('strlen'), $uri);
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $uri,
            $script,
            $scriptLen
        );
        $prefixOk = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $longer = $context->builder->icmp(Builder::INT_UGT, $uriLen, $scriptLen);
        $canDerive = $context->builder->and($prefixOk, $longer);

        // Safe GEP base: when !canDerive, index 0 on uri (NUL or first char) — never past end.
        $safeIndex = $context->builder->select(
            $canDerive,
            $scriptLen,
            $context->getTypeFromString('size_t')->constInt(0, false)
        );
        $derivedPtr = $context->builder->gep($uri, $safeIndex);
        $derivedFirst = $context->builder->load($derivedPtr);
        $derivedIsQueryOnly = $context->builder->icmp(
            Builder::INT_EQ,
            $derivedFirst,
            $i8->constInt(ord('?'), false)
        );
        $derivedClean = $context->builder->select($derivedIsQueryOnly, $empty, $derivedPtr);
        $derivedOrEmpty = $context->builder->select($canDerive, $derivedClean, $empty);
        $resolved = $context->builder->select($envEmpty, $derivedOrEmpty, $fromEnv);
        $context->builder->store($resolved, $outSlot);

        return $outSlot;
    }

    private static function entryAllocaInBlock(Context $context, \PHPLLVM\BasicBlock $block, $type): Value
    {
        $saved = $context->builder->getInsertBlock();
        $context->builder->positionAtEnd($block);
        $slot = $context->builder->alloca($type, 1);
        $context->builder->positionAtEnd($saved);

        return $slot;
    }

    private static function isCstrSlotEmpty(Context $context, Value $slot): Value
    {
        return self::isCstrEmpty($context, $context->builder->load($slot));
    }

    private static function isCstrEmpty(Context $context, Value $cstr): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($cstr),
            $i8->constInt(0, false)
        );
    }

    private static function parseFormEncoded(Context $context, Value $ht, Value $encodedStr): void
    {
        $context->builder->call(
            $context->lookupFunction('__compiler_parse_str'),
            $ht,
            $encodedStr
        );
    }

    private static function parseFormEncodedFromCstrSlot(Context $context, Value $ht, Value $cstrSlot): void
    {
        self::parseFormEncoded($context, $ht, self::cstrToPhpcString($context, $cstrSlot));
    }

    private static function parseCookieFromCstrSlot(Context $context, Value $ht, Value $cstrSlot): void
    {
        $context->builder->call(
            $context->lookupFunction('__compiler_parse_cookie_header'),
            $ht,
            self::cstrToPhpcString($context, $cstrSlot)
        );
    }

    private static function populatePostBodyFromCstrSlot(
        Context $context,
        Value $postHt,
        Value $filesHt,
        Value $contentTypeCstr,
        Value $postBodyCstr
    ): void {
        $fn = $context->builder->getInsertBlock()->getParent();
        $multipartBb = $fn->appendBasicBlock('sg_user_refresh_post_multipart');
        $urlencodedBb = $fn->appendBasicBlock('sg_user_refresh_post_urlencoded');
        $doneBb = $fn->appendBasicBlock('sg_user_refresh_post_done');

        $contentType = $context->builder->load($contentTypeCstr);
        $contentTypeEmpty = self::isCstrEmpty($context, $contentType);
        $needle = self::literalCstr($context, 'multipart/form-data');
        $sizeT = $context->getTypeFromString('size_t');
        // Prefer libc strncmp — CaseCompare may steal strncasecmp's i8* ABI (#5965).
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $contentType,
            $needle,
            $sizeT->constInt(19, false)
        );
        $prefixMatch = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $context->getTypeFromString('int32')->constInt(0, false)
        );
        $isMultipart = $context->builder->and(
            $context->builder->not($contentTypeEmpty),
            $prefixMatch
        );
        $context->builder->branchIf($isMultipart, $multipartBb, $urlencodedBb);

        $context->builder->positionAtEnd($multipartBb);
        $context->builder->call(
            $context->lookupFunction('__compiler_multipart_populate_post_body'),
            $postHt,
            $contentType,
            $context->builder->load($postBodyCstr)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($urlencodedBb);
        self::parseFormEncodedFromCstrSlot($context, $postHt, $postBodyCstr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function cstrToPhpcString(Context $context, Value $cstrSlot): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $cstr = $context->builder->load($cstrSlot);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $lenI64 = $context->builder->zExt($len, $i64);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $cstr
        );
    }

    private static function setServerKeyFromCstrIfNonEmpty(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        string $key,
        Value $valCstrSlot
    ): void {
        $empty = self::isCstrSlotEmpty($context, $valCstrSlot);
        $skipBb = $fn->appendBasicBlock('sg_user_refresh_skip_'.$key);
        $setBb = $fn->appendBasicBlock('sg_user_refresh_set_'.$key);
        $nextBb = $fn->appendBasicBlock('sg_user_refresh_after_'.$key);
        $context->builder->branchIf($empty, $skipBb, $setBb);
        $context->builder->positionAtEnd($setBb);
        self::setServerKeyFromCstr($context, $ht, $key, $valCstrSlot);
        $context->builder->branch($nextBb);
        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($nextBb);
        $context->builder->positionAtEnd($nextBb);
    }

    private static function fillServerFromProcessEnviron(Context $context, LlvmFunction $fn, Value $serverHt): void
    {
        unset($fn);
        EnvironMirrorRuntime::emitFillCall($context, $serverHt);
    }

    private static function setServerKeyFromCstr(Context $context, Value $ht, string $key, Value $valCstrSlot): void
    {
        $keyStr = $context->builder->load($context->constantStringFromString($key));
        $valStr = self::cstrToPhpcString($context, $valCstrSlot);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
    }

    private static function setServerKeyFromLiteral(Context $context, Value $ht, string $key, string $literal): void
    {
        $keyStr = $context->builder->load($context->constantStringFromString($key));
        $valStr = $context->builder->load($context->constantStringFromString($literal));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
    }

    private static function literalCstr(Context $context, string $literal): Value
    {
        $loaded = $context->builder->load($context->constantStringFromString($literal));
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($loaded, $map['value']);
    }

    private static function sgGlobalPtr(Context $context, string $name): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('JitSuperglobalRefreshKernel global missing: '.$name);
        }
        $htPtr = $context->getTypeFromString('__hashtable__*');

        return $context->builder->pointerCast($global, $htPtr->pointerType(0));
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
    }

    /**
     * Type::register declares empty ABI shells; lookup alone is not enough (#20932 regression).
     * Fill thin-AOT link stubs so ScriptExit / helper-runtime units resolve pending-header ABI.
     */
    private static function ensureHeaderQueueExternal(Context $context): void
    {
        PendingHeadersRuntime::ensureThinAotLinkStubs($context);
    }

    /** Real POST populate before user-script refresh LLVM emit (#15624). */
    private static function ensureEmitRefreshPrerequisites(Context $context): void
    {
        LibcExtern::register($context);
        StringGetenv::ensureLibcGetenv($context);
        self::ensureGlobals($context);
        ParseStrRuntime::ensureUserScriptLinked($context);
        MultipartRuntime::ensureUserScriptLinked($context);
        EnvironMirrorRuntime::ensureLinked($context);
        self::ensureHeaderQueueExternal($context);
    }

    /** preg prelink defers user init — link refresh deps without nested Multipart JIT (#16075). */
    public static function ensureDeferredEmitPrerequisites(Context $context): void
    {
        LibcExtern::register($context);
        StringGetenv::ensureLibcGetenv($context);
        ParseStrRuntime::ensureUserScriptLinked($context);
        MultipartRuntime::ensureUserScriptNoOpPopulateStub($context);
        EnvironMirrorRuntime::ensureLinked($context);
        self::ensureGlobals($context);
        self::ensureHeaderQueueExternal($context);
    }

    private static function captureInsertBlock(Context $context): ?\PHPLLVM\BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?\PHPLLVM\BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }

    private static function isUserScriptRefresh(LlvmFunction $fn): bool
    {
        foreach ($fn->getBasicBlocks() as $block) {
            if ('sg_user_refresh_entry' === $block->getName()) {
                return true;
            }
        }

        return false;
    }

    private static function clearFunctionBody(LlvmFunction $fn): void
    {
        foreach (array_reverse($fn->getBasicBlocks()) as $block) {
            $block->delete();
        }
    }
}
