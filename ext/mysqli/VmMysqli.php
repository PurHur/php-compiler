<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExceptionSupport;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\VM\Variable;

/**
 * mysqli VM class (php-src ext/mysqli/mysqli.c; #3435).
 *
 * Phase-1: host bridge when ext/mysqli loaded on harness PHP; stub API otherwise.
 * Live connections use host \mysqli via reflection; without ext/mysqli on the host,
 * mysqli_connect() returns false and sets connect_errno/connect_error.
 */
final class VmMysqli
{
    public const CLASS_LC = 'mysqli';

    /** @var array<int, MysqliState> */
    private static array $store = [];

    private static int $connectErrno = 0;

    private static string $connectError = '';

    /** Open mysqli links counted for mysqli_get_links_stats() (php-src MyG(num_links); #22183). */
    private static int $numLinks = 0;

    /** Active persistent links (php-src MyG(num_active_persistent); #22183). */
    private static int $numActivePersistent = 0;

    /** Inactive/cached persistent links (php-src MyG(num_inactive_persistent); #22183). */
    private static int $numInactivePersistent = 0;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['execute_query'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('mysqli');
        $entry->isInternal = true;

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new MysqliConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            'query' => new MysqliQuery(),
            'execute_query' => new MysqliExecuteQuery(),
            'close' => new MysqliClose(),
            'real_escape_string' => new MysqliRealEscapeString(),
            'prepare' => new MysqliPrepare(),
            'autocommit' => new MysqliAutocommit(),
            'begin_transaction' => new MysqliBeginTransaction(),
            'commit' => new MysqliCommit(),
            'rollback' => new MysqliRollback(),
            'savepoint' => new MysqliSavepoint(),
            'release_savepoint' => new MysqliReleaseSavepoint(),
            'refresh' => new MysqliRefresh(),
            'get_connection_stats' => new MysqliGetConnectionStats(),
            'real_connect' => new MysqliRealConnect(),
            'options' => new MysqliOptions(),
            'set_charset' => new MysqliSetCharset(),
            'multi_query' => new MysqliMultiQuery(),
            'real_query' => new MysqliRealQuery(),
            'next_result' => new MysqliNextResult(),
            'more_results' => new MysqliMoreResults(),
            'store_result' => new MysqliStoreResult(),
            'use_result' => new MysqliUseResult(),
            'poll' => new MysqliPoll(),
            'reap_async_query' => new MysqliReapAsyncQuery(),
            'insert_id' => new MysqliInsertId(),
            'field_count' => new MysqliFieldCountMethod(),
            'sqlstate' => new MysqliSqlstateMethod(),
            'warning_count' => new MysqliWarningCountMethod(),
            'character_set_name' => new MysqliCharacterSetName(),
            'get_charset' => new MysqliGetCharset(),
            'get_server_info' => new MysqliGetServerInfo(),
            'get_host_info' => new MysqliGetHostInfo(),
            'get_proto_info' => new MysqliGetProtoInfo(),
            'get_server_version' => new MysqliGetServerVersion(),
            'get_client_info' => new MysqliGetClientInfoMethod(),
            'ssl_set' => new MysqliSslSet(),
            'ping' => new MysqliPing(),
            'select_db' => new MysqliSelectDb(),
            'change_user' => new MysqliChangeUser(),
            'thread_id' => new MysqliThreadId(),
            'kill' => new MysqliKill(),
            'stmt_init' => new MysqliStmtInit(),
            'dump_debug_info' => new MysqliDumpDebugInfo(),
            'get_warnings' => new MysqliGetWarnings(),
        ];
        foreach ($methods as $name => $method) {
            $lcName = strtolower($name);
            $entry->methods[$lcName] = $method;
            $entry->methodVisibility[$lcName] = $pub;
            if ($lcName !== $name) {
                $entry->methodNames[$lcName] = $name;
            }
        }
        // mysqli::poll is static (php-src mysqli.stub.php; #22163).
        $entry->methodVisibility['poll'] = CfgFunc::FLAG_STATIC | $pub;

        // php-src ext/mysqli/mysqli.stub.php — execute_query(): mysqli_result|bool (#27712)
        $executeQueryRet = ReflectionTypeSupport::cfgTypeFromLabel('mysqli_result|bool');
        if (null !== $executeQueryRet) {
            $entry->methodReturnDeclaredTypes['execute_query'] = $executeQueryRet;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function connect(
        ?string $hostname,
        ?string $username,
        ?string $password,
        ?string $database,
        ?int $port,
        ?string $socket
    ): ?ObjectEntry {
        if (!MysqliExtensionPolicy::hasNativeDriver()) {
            self::$connectErrno = 2002;
            self::$connectError = 'mysqli extension not available on host PHP';

            return null;
        }

        $hostname = $hostname ?? ini_get('mysqli.default_host') ?: '127.0.0.1';
        $username = $username ?? ini_get('mysqli.default_user') ?: '';
        $password = $password ?? ini_get('mysqli.default_pw') ?: '';
        $database = $database ?? '';
        $port = $port ?? (int) (ini_get('mysqli.default_port') ?: 3306);
        $socket = $socket ?? ini_get('mysqli.default_socket') ?: '';

        try {
            $native = @new \mysqli($hostname, $username, $password, $database, $port, $socket);
        } catch (\mysqli_sql_exception $e) {
            self::$connectErrno = $e->getCode();
            self::$connectError = $e->getMessage();

            return null;
        }

        if ($native->connect_errno) {
            self::$connectErrno = $native->connect_errno;
            self::$connectError = $native->connect_error ?? 'Unknown error';

            return null;
        }

        self::$connectErrno = 0;
        self::$connectError = '';

        return self::wrapNative($native);
    }

    private static function wrapNative(\mysqli $native): ObjectEntry
    {
        $ctx = null;
        foreach (self::$store as $s) {
            if (null !== $s->ctx) {
                $ctx = $s->ctx;
                break;
            }
        }
        if (null === $ctx) {
            throw new \LogicException('No VM context available for mysqli');
        }
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('mysqli class not registered');
        }
        $entry = new ObjectEntry($class);
        $state = new MysqliState();
        $state->native = $native;
        $state->ctx = $ctx;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;
        self::noteLinkOpened($state);

        return $entry;
    }

    public static function wrapNativeWithContext(Context $ctx, \mysqli $native): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('mysqli class not registered');
        }
        $entry = new ObjectEntry($class);
        $state = new MysqliState();
        $state->native = $native;
        $state->ctx = $ctx;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;
        self::noteLinkOpened($state);

        return $entry;
    }

    /**
     * mysqli_get_links_stats() payload (php-src ext/mysqli/mysqli_nonapi.c; #22183).
     *
     * @return array{total: int, active_plinks: int, cached_plinks: int}
     */
    public static function linksStats(): array
    {
        return [
            'total' => self::$numLinks,
            'active_plinks' => self::$numActivePersistent,
            'cached_plinks' => self::$numInactivePersistent,
        ];
    }

    /** Count a successfully opened link (php-src MyG(num_links)++; #22183). */
    public static function noteLinkOpened(MysqliState $state, bool $persistent = false): void
    {
        if ($state->countedInLinksStats) {
            return;
        }
        $state->countedInLinksStats = true;
        $state->persistent = $persistent;
        ++self::$numLinks;
        if ($persistent) {
            ++self::$numActivePersistent;
        }
    }

    /** Drop a closed link from counters (php-src MyG(num_links)--; #22183). */
    public static function noteLinkClosed(MysqliState $state): void
    {
        if (!$state->countedInLinksStats) {
            return;
        }
        $state->countedInLinksStats = false;
        if (self::$numLinks > 0) {
            --self::$numLinks;
        }
        if ($state->persistent) {
            // Without a persistent free-pool, treat close as ending the active plink.
            if (self::$numActivePersistent > 0) {
                --self::$numActivePersistent;
            }
            $state->persistent = false;
        }
    }

    public static function setConnectError(int $errno, string $error): void
    {
        self::$connectErrno = $errno;
        self::$connectError = $error;
    }

    public static function connectErrno(): int
    {
        return self::$connectErrno;
    }

    public static function connectError(): string
    {
        return self::$connectError;
    }

    public static function attachState(ObjectEntry $entry, MysqliState $state): void
    {
        self::$store[$entry->id] = $state;
        $entry->constructed = true;
    }

    public static function state(ObjectEntry $entry, ?Context $ctx = null): MysqliState
    {
        if (!isset(self::$store[$entry->id])) {
            if (null !== $ctx) {
                self::throwUninitialized();
            }
            throw new \LogicException('mysqli object has not been correctly initialized');
        }

        return self::$store[$entry->id];
    }

    public static function requireNative(ObjectEntry $entry, Context $ctx): \mysqli
    {
        $state = self::state($entry, $ctx);
        if (null === $state->native) {
            throw new \LogicException('mysqli connection is closed');
        }

        return $state->native;
    }

    /**
     * Optional bind-in-execute params list (php-src mysqli_execute_query; #21895).
     *
     * @return list<mixed>|null
     */
    public static function paramsListFromVariable(Variable $var, string $label, int $argIndex0): ?array
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }
        if (Variable::TYPE_ARRAY !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($params) must be of type ?array, %s given',
                $label,
                $argIndex0 + 1,
                MysqliClassMethod::typeLabelPublic($resolved)
            ));
        }
        $out = [];
        $i = 0;
        foreach ($resolved->toArray()->iterateKeyed(true) as [$keyVar, $itemVar]) {
            if (!self::isListIndexKey($keyVar, $i)) {
                throw new \ValueError(\sprintf(
                    '%s(): Argument #%d ($params) must be a list array',
                    $label,
                    $argIndex0 + 1
                ));
            }
            $out[] = VmMysqliStmt::scalarFromVariable($itemVar->resolveIndirect());
            ++$i;
        }

        return $out;
    }

    private static function isListIndexKey(Variable $keyVar, int $expected): bool
    {
        $key = $keyVar->resolveIndirect();
        if (Variable::TYPE_INTEGER === $key->type) {
            return $key->toInt() === $expected;
        }
        if (Variable::TYPE_STRING === $key->type) {
            return (string) $expected === $key->toString();
        }

        return false;
    }

    /**
     * mysqli_execute_query / mysqli::execute_query (php-src ext/mysqli/mysqli_api.c; #21895).
     *
     * @param list<mixed>|null $params
     *
     * @return ObjectEntry|bool
     */
    public static function executeQueryOnLink(
        ObjectEntry $link,
        string $query,
        ?array $params,
        Context $ctx,
        string $label,
        int $paramsArgPos
    ): ObjectEntry|bool {
        if (!MysqliExtensionPolicy::hasNativeDriver()) {
            return false;
        }
        $native = self::requireNative($link, $ctx);
        if (null !== $params && !\array_is_list($params)) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($params) must be a list array',
                $label,
                $paramsArgPos
            ));
        }

        // Prefer host PHP 8.2+ execute_query when available.
        if (\is_callable([$native, 'execute_query'])) {
            try {
                $result = null === $params
                    ? $native->execute_query($query)
                    : $native->execute_query($query, $params);
            } catch (\ValueError $e) {
                $msg = $e->getMessage();
                if (\str_contains($msg, 'must be a list array')
                    || \str_contains($msg, 'must consist of exactly')) {
                    throw new \ValueError(\preg_replace(
                        '/^[^:]+:/',
                        $label.'():',
                        $msg,
                        1
                    ) ?? $msg);
                }
                throw $e;
            }
            if (true === $result) {
                return true;
            }
            if (false === $result) {
                return false;
            }

            return VmMysqliResult::wrap($ctx, $result);
        }

        // Compose prepare → execute(+params) → get_result on older host mysqli.
        $stmt = $native->prepare($query);
        if (false === $stmt) {
            return false;
        }
        if (null !== $params) {
            $paramCount = (int) $stmt->param_count;
            $n = \count($params);
            if ($n !== $paramCount) {
                $stmt->close();
                throw new \ValueError(\sprintf(
                    '%s(): Argument #%d ($params) must consist of exactly %d elements, %d present',
                    $label,
                    $paramsArgPos,
                    $paramCount,
                    $n
                ));
            }
            if (!$stmt->execute($params)) {
                $stmt->close();

                return false;
            }
        } elseif (!$stmt->execute()) {
            $stmt->close();

            return false;
        }
        if (0 === (int) $stmt->field_count) {
            $stmt->close();

            return true;
        }
        if (!\method_exists($stmt, 'get_result')) {
            $stmt->close();

            return false;
        }
        $result = $stmt->get_result();
        $stmt->close();
        if (false === $result) {
            return false;
        }

        return VmMysqliResult::wrap($ctx, $result);
    }

    /** @return never */
    private static function throwUninitialized(): void
    {
        throw new \mysqli_sql_exception('mysqli object is not fully initialized', 0);
    }

    public static function destroyState(ObjectEntry $entry): void
    {
        unset(self::$store[$entry->id]);
    }

    public static function assignRow(Variable $returnVar, array $row): void
    {
        $ht = new HashTable();
        foreach ($row as $key => $item) {
            $slot = new Variable();
            if (null === $item) {
                $slot->null();
            } elseif (\is_int($item)) {
                $slot->int($item);
            } elseif (\is_float($item)) {
                $slot->float($item);
            } else {
                $slot->string((string) $item);
            }
            $ht->add(\is_int($key) ? (string) $key : $key, $slot);
        }
        $returnVar->array($ht);
    }

    public static function initStore(Context $ctx): void
    {
        $sentinel = new MysqliState();
        $sentinel->ctx = $ctx;
        self::$store[-1] = $sentinel;
    }

    public static function autocommitOnLink(ObjectEntry $entry, Context $ctx, bool $mode): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->autocommit($mode);
    }

    public static function beginTransactionOnLink(ObjectEntry $entry, Context $ctx, int $flags = 0, ?string $name = null): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->begin_transaction($flags, $name);
    }

    public static function commitOnLink(ObjectEntry $entry, Context $ctx, int $flags = 0, ?string $name = null): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->commit($flags, $name);
    }

    public static function rollbackOnLink(ObjectEntry $entry, Context $ctx, int $flags = 0, ?string $name = null): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->rollback($flags, $name);
    }

    public static function savepointOnLink(ObjectEntry $entry, Context $ctx, string $name): bool
    {
        $native = self::requireNative($entry, $ctx);
        if (method_exists($native, 'savepoint')) {
            return $native->savepoint($name);
        }

        return true === $native->query(self::savepointSql($name));
    }

    public static function releaseSavepointOnLink(ObjectEntry $entry, Context $ctx, string $name): bool
    {
        $native = self::requireNative($entry, $ctx);
        if (method_exists($native, 'release_savepoint')) {
            return $native->release_savepoint($name);
        }

        return true === $native->query(self::releaseSavepointSql($name));
    }

    private static function savepointSql(string $name): string
    {
        return 'SAVEPOINT `'.str_replace('`', '``', $name).'`';
    }

    private static function releaseSavepointSql(string $name): string
    {
        return 'RELEASE SAVEPOINT `'.str_replace('`', '``', $name).'`';
    }

    public static function refreshOnLink(ObjectEntry $entry, Context $ctx, int $options): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->refresh($options);
    }

    /** @return array<string, int|float|string|null> */
    public static function connectionStatsOnLink(ObjectEntry $entry, Context $ctx): array
    {
        $native = self::requireNative($entry, $ctx);
        if (!method_exists($native, 'get_connection_stats')) {
            return [];
        }
        $stats = $native->get_connection_stats();
        if (!\is_array($stats)) {
            return [];
        }

        return $stats;
    }

    public static function realConnectOnLink(
        ObjectEntry $entry,
        Context $ctx,
        ?string $hostname,
        ?string $username,
        ?string $password,
        ?string $database,
        ?int $port,
        ?string $socket,
        int $flags = 0
    ): bool {
        $native = self::requireNativeOrInit($entry, $ctx);
        $hostname = $hostname ?? ini_get('mysqli.default_host') ?: null;
        $username = $username ?? ini_get('mysqli.default_user') ?: null;
        $password = $password ?? ini_get('mysqli.default_pw') ?: null;
        $database = $database ?? null;
        $port = $port ?? (int) (ini_get('mysqli.default_port') ?: 3306);
        $socket = $socket ?? ini_get('mysqli.default_socket') ?: null;

        $ok = $native->real_connect($hostname, $username, $password, $database, $port, $socket, $flags);
        if ($ok) {
            self::noteLinkOpened(self::state($entry, $ctx));
        }

        return $ok;
    }

    public static function optionsOnLink(ObjectEntry $entry, Context $ctx, int $option, mixed $value): bool
    {
        $native = self::requireNativeOrInit($entry, $ctx);

        return $native->options($option, $value);
    }

    public static function setCharsetOnLink(ObjectEntry $entry, Context $ctx, string $charset): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->set_charset($charset);
    }

    public static function multiQueryOnLink(ObjectEntry $entry, Context $ctx, string $query): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->multi_query($query);
    }

    public static function realQueryOnLink(ObjectEntry $entry, Context $ctx, string $query): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->real_query($query);
    }

    public static function nextResultOnLink(ObjectEntry $entry, Context $ctx): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->next_result();
    }

    public static function moreResultsOnLink(ObjectEntry $entry, Context $ctx): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->more_results();
    }

    public static function storeResultOnLink(ObjectEntry $entry, Context $ctx, int $flags = 0): ObjectEntry|bool
    {
        $native = self::requireNative($entry, $ctx);
        $result = $native->store_result($flags);
        if (false === $result) {
            return false;
        }

        return VmMysqliResult::wrap($ctx, $result);
    }

    public static function useResultOnLink(ObjectEntry $entry, Context $ctx): ObjectEntry|bool
    {
        $native = self::requireNative($entry, $ctx);
        $result = $native->use_result();
        if (false === $result) {
            return false;
        }

        return VmMysqliResult::wrap($ctx, $result);
    }

    /**
     * mysqli_poll / mysqli::poll — php-src mysqli_poll (#22163).
     *
     * Host bridge when ext/mysqli provides mysqli_poll; otherwise returns false.
     */
    public static function executePoll(Frame $frame, string $label, int $argBase): void
    {
        $argc = \count($frame->calledArgs);
        $needed = $argBase + 4;
        if ($argc < $needed) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at least %d arguments, %d given',
                $label,
                4,
                max(0, $argc - $argBase)
            ));
        }
        $ctx = $frame->vmContext ?? throw new \LogicException($label.'() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }

        $readVar = $frame->calledArgs[$argBase];
        $errorVar = $frame->calledArgs[$argBase + 1];
        $rejectVar = $frame->calledArgs[$argBase + 2];
        $sec = MysqliProceduralLink::optionalIntArg($frame, $argBase + 3, 0);
        $usec = $argc > $argBase + 4
            ? MysqliProceduralLink::optionalIntArg($frame, $argBase + 4, 0)
            : 0;

        $readResolved = $readVar->resolveIndirect();
        $errorResolved = $errorVar->resolveIndirect();
        $rejectResolved = $rejectVar->resolveIndirect();

        $readNull = Variable::TYPE_NULL === $readResolved->type;
        $errorNull = Variable::TYPE_NULL === $errorResolved->type;

        if (!$readNull && Variable::TYPE_ARRAY !== $readResolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($read) must be of type ?array, %s given',
                $label,
                MysqliClassMethod::typeLabelPublic($readResolved)
            ));
        }
        if (!$errorNull && Variable::TYPE_ARRAY !== $errorResolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #2 ($error) must be of type ?array, %s given',
                $label,
                MysqliClassMethod::typeLabelPublic($errorResolved)
            ));
        }
        if (Variable::TYPE_ARRAY !== $rejectResolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #3 ($reject) must be of type array, %s given',
                $label,
                MysqliClassMethod::typeLabelPublic($rejectResolved)
            ));
        }

        if (!MysqliExtensionPolicy::hasNativeDriver() || !\function_exists('\\mysqli_poll')) {
            $frame->returnVar->bool(false);

            return;
        }

        $readMap = $readNull ? null : self::nativeLinksFromArrayVar($readResolved, $label, 1);
        $errorMap = $errorNull ? null : self::nativeLinksFromArrayVar($errorResolved, $label, 2);
        $rejectMap = self::nativeLinksFromArrayVar($rejectResolved, $label, 3);

        $readNatives = null === $readMap ? null : $readMap['natives'];
        $errorNatives = null === $errorMap ? null : $errorMap['natives'];
        $rejectNatives = $rejectMap['natives'];

        $ready = \mysqli_poll($readNatives, $errorNatives, $rejectNatives, $sec, $usec);
        if (false === $ready) {
            $frame->returnVar->bool(false);

            return;
        }

        if (null !== $readMap) {
            self::writeBackLinkArray($readVar, $readNatives ?? [], $readMap['byId']);
        }
        if (null !== $errorMap) {
            self::writeBackLinkArray($errorVar, $errorNatives ?? [], $errorMap['byId']);
        }
        self::writeBackLinkArray($rejectVar, $rejectNatives, $rejectMap['byId']);

        $frame->returnVar->int((int) $ready);
    }

    /**
     * @return array{natives: list<\mysqli>, byId: array<int, ObjectEntry>}
     */
    private static function nativeLinksFromArrayVar(Variable $arrayVar, string $label, int $argNum): array
    {
        $natives = [];
        $byId = [];
        foreach ($arrayVar->toArray()->iterateKeyed(true) as $pair) {
            [, $itemVar] = $pair;
            $item = $itemVar->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $item->type) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d must contain only mysqli objects, %s given',
                    $label,
                    $argNum,
                    MysqliClassMethod::typeLabelPublic($item)
                ));
            }
            $entry = $item->toObject();
            if (strtolower($entry->class->name) !== self::CLASS_LC) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #%d must contain only mysqli objects, %s given',
                    $label,
                    $argNum,
                    $entry->class->name
                ));
            }
            $ctx = self::state($entry)->ctx ?? throw new \LogicException('No VM context');
            $native = self::requireNative($entry, $ctx);
            $natives[] = $native;
            $byId[spl_object_id($native)] = $entry;
        }

        return ['natives' => $natives, 'byId' => $byId];
    }

    /**
     * @param list<\mysqli> $natives
     * @param array<int, ObjectEntry> $byId
     */
    private static function writeBackLinkArray(Variable $targetVar, array $natives, array $byId): void
    {
        $targetVar = $targetVar->resolveIndirect();
        $ht = new HashTable();
        $index = 0;
        foreach ($natives as $native) {
            if (!$native instanceof \mysqli) {
                continue;
            }
            $id = spl_object_id($native);
            if (!isset($byId[$id])) {
                continue;
            }
            $slot = new Variable();
            $slot->object($byId[$id]);
            $ht->addIndex($index, $slot);
            ++$index;
        }
        $replacement = new Variable();
        $replacement->array($ht);
        $targetVar->copyFrom($replacement);
    }

    public static function reapAsyncQueryOnLink(ObjectEntry $entry, Context $ctx): ObjectEntry|bool
    {
        $native = self::requireNative($entry, $ctx);
        if (!\method_exists($native, 'reap_async_query')) {
            return false;
        }
        $result = $native->reap_async_query();
        if (true === $result) {
            return true;
        }
        if (false === $result) {
            return false;
        }

        return VmMysqliResult::wrap($ctx, $result);
    }

    public static function infoOnLink(ObjectEntry $entry, Context $ctx): ?string
    {
        $native = self::requireNative($entry, $ctx);
        $info = $native->info;

        return false === $info || null === $info ? null : (string) $info;
    }

    public static function statOnLink(ObjectEntry $entry, Context $ctx): ?string
    {
        $native = self::requireNative($entry, $ctx);
        $stat = $native->stat();
        if (false === $stat) {
            return null;
        }

        return (string) $stat;
    }

    /** mysqli_ping() — php-src ext/mysqli/mysqli_api.c (#22174). */
    public static function pingOnLink(ObjectEntry $entry, Context $ctx): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->ping();
    }

    /** mysqli_select_db() — php-src ext/mysqli/mysqli_api.c (#22174). */
    public static function selectDbOnLink(ObjectEntry $entry, Context $ctx, string $database): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->select_db($database);
    }

    /** mysqli_change_user() — php-src ext/mysqli/mysqli_api.c (#22174). */
    public static function changeUserOnLink(
        ObjectEntry $entry,
        Context $ctx,
        string $username,
        string $password,
        ?string $database
    ): bool {
        $native = self::requireNative($entry, $ctx);

        return $native->change_user($username, $password, $database);
    }

    /** mysqli_thread_id() — php-src ext/mysqli/mysqli_api.c (#22174). */
    public static function threadIdOnLink(ObjectEntry $entry, Context $ctx): int
    {
        $native = self::requireNative($entry, $ctx);

        return (int) $native->thread_id;
    }

    /** mysqli_kill() — php-src ext/mysqli/mysqli_api.c (#22174). */
    public static function killOnLink(ObjectEntry $entry, Context $ctx, int $processId): bool
    {
        $native = self::requireNative($entry, $ctx);

        return $native->kill($processId);
    }

    /**
     * mysqli_get_client_stats() — php-src ext/mysqli/mysqli_nonapi.c (#22174).
     *
     * @return array<string, int|float|string|null>
     */
    public static function clientStats(): array
    {
        if (!MysqliExtensionPolicy::hasNativeDriver() || !\function_exists('\\mysqli_get_client_stats')) {
            return [];
        }
        $stats = \mysqli_get_client_stats();
        if (!\is_array($stats)) {
            return [];
        }

        return $stats;
    }

    /**
     * mysqli_dump_debug_info() — php-src ext/mysqli/mysqli.c (#22223).
     *
     * Host bridge; returns false when the native driver cannot dump.
     */
    public static function dumpDebugInfoOnLink(ObjectEntry $entry, Context $ctx): bool
    {
        $native = self::requireNative($entry, $ctx);
        if (!\method_exists($native, 'dump_debug_info')) {
            return false;
        }

        return (bool) $native->dump_debug_info();
    }

    /**
     * mysqli_debug() — php-src ext/mysqli/mysqli.c (#22223).
     *
     * Connectionless; Zend returns true after applying mysqlnd debug options.
     */
    public static function debugOptions(string $options): bool
    {
        if (MysqliExtensionPolicy::hasNativeDriver() && \function_exists('\\mysqli_debug')) {
            return (bool) \mysqli_debug($options);
        }

        // No host mysqlnd debug hook — still succeed like a no-op config apply.
        return true;
    }

    /** @return int|string */
    public static function insertIdOnLink(ObjectEntry $entry, Context $ctx)
    {
        $native = self::requireNative($entry, $ctx);

        return $native->insert_id;
    }

    public static function fieldCountOnLink(ObjectEntry $entry, Context $ctx): int
    {
        $native = self::requireNative($entry, $ctx);

        return (int) $native->field_count;
    }

    public static function sqlstateOnLink(ObjectEntry $entry, Context $ctx): string
    {
        $native = self::requireNative($entry, $ctx);

        return (string) $native->sqlstate;
    }

    /**
     * mysqli_error_list() — rows of {errno,sqlstate,error} (#22225).
     *
     * @return list<array{errno: int, sqlstate: string, error: string}>
     */
    public static function errorListOnLink(ObjectEntry $entry, Context $ctx): array
    {
        if (!isset(self::$store[$entry->id])) {
            return [];
        }
        $native = self::$store[$entry->id]->native;
        if (null === $native) {
            return [];
        }
        $list = $native->error_list;
        if (!\is_array($list)) {
            return [];
        }

        return self::normalizeErrorList($list);
    }

    /**
     * @param array<int|string, mixed> $list
     * @return list<array{errno: int, sqlstate: string, error: string}>
     */
    public static function normalizeErrorList(array $list): array
    {
        $out = [];
        foreach ($list as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $out[] = [
                'errno' => (int) ($row['errno'] ?? 0),
                'sqlstate' => (string) ($row['sqlstate'] ?? '00000'),
                'error' => (string) ($row['error'] ?? ''),
            ];
        }

        return $out;
    }

    public static function warningCountOnLink(ObjectEntry $entry, Context $ctx): int
    {
        $native = self::requireNative($entry, $ctx);

        return (int) $native->warning_count;
    }

    /**
     * mysqli_get_warnings() — php-src ext/mysqli/mysqli_nonapi.c (#22224).
     *
     * Returns first mysqli_warning or null (caller maps to false).
     */
    public static function getWarningsOnLink(ObjectEntry $entry, Context $ctx): ?ObjectEntry
    {
        $native = self::requireNative($entry, $ctx);
        if ((int) $native->warning_count < 1) {
            return null;
        }
        if (\method_exists($native, 'get_warnings')) {
            $w = $native->get_warnings();
            if (false === $w || null === $w) {
                return null;
            }

            return VmMysqliWarning::fromNativeChain($ctx, $w);
        }

        return self::warningsFromShowWarnings($ctx, $native);
    }

    /**
     * Fallback when host lacks get_warnings(): SHOW WARNINGS (php-src php_get_warnings).
     */
    public static function warningsFromShowWarnings(Context $ctx, \mysqli $native): ?ObjectEntry
    {
        $result = @$native->query('SHOW WARNINGS');
        if (false === $result || null === $result) {
            return null;
        }
        $rows = [];
        while ($row = $result->fetch_row()) {
            if (!\is_array($row) || \count($row) < 3) {
                continue;
            }
            // Level, Code, Message — php_new_warning hardcodes sqlstate HY000.
            $rows[] = [
                'errno' => (int) $row[1],
                'message' => (string) $row[2],
                'sqlstate' => 'HY000',
            ];
        }
        $result->free();
        if ($rows === []) {
            return null;
        }

        return VmMysqliWarning::fromRows($ctx, $rows);
    }

    public static function characterSetNameOnLink(ObjectEntry $entry, Context $ctx): string
    {
        $native = self::requireNative($entry, $ctx);

        return (string) $native->character_set_name();
    }

    public static function getCharsetOnLink(ObjectEntry $entry, Context $ctx): ?ObjectEntry
    {
        $native = self::requireNative($entry, $ctx);
        $charset = $native->get_charset();
        if (false === $charset || null === $charset) {
            return null;
        }

        return VmMysqliResult::importNativeObject($ctx, $charset);
    }

    public static function serverInfoOnLink(ObjectEntry $entry, Context $ctx): string
    {
        $native = self::requireNative($entry, $ctx);

        return (string) $native->server_info;
    }

    public static function hostInfoOnLink(ObjectEntry $entry, Context $ctx): string
    {
        $native = self::requireNative($entry, $ctx);

        return (string) $native->host_info;
    }

    public static function protoInfoOnLink(ObjectEntry $entry, Context $ctx): int
    {
        $native = self::requireNative($entry, $ctx);

        return (int) $native->protocol_version;
    }

    public static function serverVersionOnLink(ObjectEntry $entry, Context $ctx): int
    {
        $native = self::requireNative($entry, $ctx);

        return (int) $native->server_version;
    }

    public static function clientInfo(): string
    {
        if (!MysqliExtensionPolicy::hasNativeDriver()) {
            return 'mysqlnd (unavailable)';
        }

        return (string) \mysqli_get_client_info();
    }

    public static function clientVersion(): int
    {
        if (!MysqliExtensionPolicy::hasNativeDriver()) {
            return 0;
        }

        return (int) \mysqli_get_client_version();
    }

    public static function clientInfoOnLink(ObjectEntry $entry, Context $ctx): string
    {
        $native = self::requireNative($entry, $ctx);
        if (method_exists($native, 'get_client_info')) {
            return (string) $native->get_client_info();
        }

        return self::clientInfo();
    }

    public static function sslSetOnLink(
        ObjectEntry $entry,
        Context $ctx,
        ?string $key,
        ?string $certificate,
        ?string $caCertificate,
        ?string $caPath,
        ?string $cipherAlgos
    ): bool {
        $native = self::requireNativeOrInit($entry, $ctx);

        return $native->ssl_set($key, $certificate, $caCertificate, $caPath, $cipherAlgos);
    }

    public static function assignInsertId(Variable $returnVar, int|string $id): void
    {
        if (\is_int($id)) {
            $returnVar->int($id);
        } elseif (is_numeric($id) && (string) (int) $id === (string) $id) {
            $returnVar->int((int) $id);
        } else {
            $returnVar->string((string) $id);
        }
    }

    public static function requireNativeOrInit(ObjectEntry $entry, Context $ctx): \mysqli
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('mysqli object has not been correctly initialized');
        }
        $state = self::$store[$entry->id];
        if (null === $state->native) {
            if (!MysqliExtensionPolicy::hasNativeDriver()) {
                throw new \LogicException('mysqli extension not available on host PHP');
            }
            $state->native = new \mysqli();
        }

        return $state->native;
    }
}

/** @internal */
final class MysqliState
{
    public ?\mysqli $native = null;

    public ?Context $ctx = null;

    /** Whether this link is included in mysqli_get_links_stats() total (#22183). */
    public bool $countedInLinksStats = false;

    /** Persistent (pconnect) link — drives active_plinks (#22183). */
    public bool $persistent = false;
}

/** @internal — mysqli_result VM wrapper. */
final class VmMysqliResult
{
    public const CLASS_LC = 'mysqli_result';

    /** @var array<int, MysqliResultState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $entry = new ClassEntry('mysqli_result');
        $entry->isInternal = true;

        $pub = CfgFunc::FLAG_PUBLIC;
        $methods = [
            'fetch_assoc' => new MysqliResultFetchAssoc(),
            'fetch_array' => new MysqliResultFetchArray(),
            'fetch_row' => new MysqliResultFetchRow(),
            'fetch_column' => new MysqliResultFetchColumn(),
            'free' => new MysqliResultFree(),
            'close' => new MysqliResultFree(),
            'free_result' => new MysqliResultFree(),
            'fetch_all' => new MysqliResultFetchAll(),
            'fetch_object' => new MysqliResultFetchObject(),
            'fetch_field' => new MysqliResultFetchField(),
            'fetch_fields' => new MysqliResultFetchFields(),
            'fetch_field_direct' => new MysqliResultFetchFieldDirect(),
            'fetch_lengths' => new MysqliResultFetchLengths(),
            'data_seek' => new MysqliResultDataSeek(),
            'field_seek' => new MysqliResultFieldSeek(),
            'field_tell' => new MysqliResultFieldTell(),
        ];
        foreach ($methods as $name => $method) {
            $lcName = strtolower($name);
            $entry->methods[$lcName] = $method;
            $entry->methodVisibility[$lcName] = $pub;
            if ($lcName !== $name) {
                $entry->methodNames[$lcName] = $name;
            }
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /** Import host stdClass / mysqli field object into a VM stdClass (#22195). */
    public static function importNativeObject(Context $ctx, object $native): ObjectEntry
    {
        $classLc = strtolower($native::class);
        $class = $ctx->classes[$classLc] ?? ($ctx->classes['stdclass'] ?? null);
        if (null === $class) {
            throw new \LogicException('stdClass is not registered');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        foreach (get_object_vars($native) as $key => $item) {
            self::assignScalarToVariable($entry->allocateProperty((string) $key), $item);
        }

        return $entry;
    }

    public static function assignScalarToVariable(Variable $slot, mixed $item): void
    {
        if (null === $item) {
            $slot->null();
        } elseif (\is_int($item)) {
            $slot->int($item);
        } elseif (\is_float($item)) {
            $slot->float($item);
        } elseif (\is_bool($item)) {
            $slot->bool($item);
        } else {
            $slot->string((string) $item);
        }
    }

    /** @return list<array<int|string, mixed>> */
    public static function fetchAllRows(\mysqli_result $native, int $mode): array
    {
        $nativeMode = match ($mode) {
            MysqliConstants::MYSQLI_ASSOC => \MYSQLI_ASSOC,
            MysqliConstants::MYSQLI_BOTH => \MYSQLI_BOTH,
            default => \MYSQLI_NUM,
        };
        $rows = $native->fetch_all($nativeMode);

        return \is_array($rows) ? $rows : [];
    }

    public static function assignRows(Variable $returnVar, array $rows): void
    {
        $ht = new HashTable();
        foreach ($rows as $i => $row) {
            $slot = new Variable();
            VmMysqli::assignRow($slot, $row);
            $ht->add((string) $i, $slot);
        }
        $returnVar->array($ht);
    }

    public static function fetchObject(
        Context $ctx,
        \mysqli_result $native,
        string $class = 'stdClass',
        array $constructorArgs = []
    ): ?ObjectEntry {
        $obj = $native->fetch_object($class, $constructorArgs);
        if (null === $obj) {
            return null;
        }

        return self::importNativeObject($ctx, $obj);
    }

    public static function fetchField(\mysqli_result $native, Context $ctx): ?ObjectEntry
    {
        $field = $native->fetch_field();
        if (false === $field || null === $field) {
            return null;
        }

        return self::importNativeObject($ctx, $field);
    }

    /** @return list<ObjectEntry> */
    public static function fetchFields(\mysqli_result $native, Context $ctx): array
    {
        $fields = $native->fetch_fields();
        if (!\is_array($fields)) {
            return [];
        }
        $out = [];
        foreach ($fields as $field) {
            if (\is_object($field)) {
                $out[] = self::importNativeObject($ctx, $field);
            }
        }

        return $out;
    }

    public static function fetchFieldDirect(\mysqli_result $native, Context $ctx, int $index): ?ObjectEntry
    {
        $field = $native->fetch_field_direct($index);
        if (false === $field || null === $field) {
            return null;
        }

        return self::importNativeObject($ctx, $field);
    }

    /** @return list<int>|null */
    public static function fetchLengths(\mysqli_result $native): ?array
    {
        $lengths = $native->fetch_lengths;
        if (!\is_array($lengths)) {
            return null;
        }

        return array_map('intval', $lengths);
    }

    /**
     * mysqli_fetch_column / mysqli_result::fetch_column — php-src mysqli_nonapi.c (#22214).
     *
     * Returns false at EOF (not null). ValueError on negative / out-of-range $column.
     *
     * @return null|int|float|string|false
     */
    public static function fetchColumn(
        \mysqli_result $native,
        int $column,
        string $funcLabel,
        int $columnArgPos
    ): mixed {
        if ($column < 0) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($column) must be greater than or equal to 0',
                $funcLabel,
                $columnArgPos
            ));
        }
        if ($column >= $native->field_count) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($column) must be less than the number of fields for this result set',
                $funcLabel,
                $columnArgPos
            ));
        }
        if (\is_callable([$native, 'fetch_column'])) {
            return $native->fetch_column($column);
        }
        $row = $native->fetch_row();
        if (null === $row || false === $row) {
            return false;
        }

        return \array_key_exists($column, $row) ? $row[$column] : null;
    }

    /** Assign scalar / false / null fetch_column result (#22214). */
    public static function assignFetchColumnResult(Variable $returnVar, mixed $value): void
    {
        if (false === $value) {
            $returnVar->bool(false);

            return;
        }
        self::assignScalarToVariable($returnVar, $value);
    }

    public static function requireResultObject(Variable $var, string $label): ObjectEntry
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($result) must be of type mysqli_result, %s given',
                $label,
                MysqliClassMethod::typeLabelPublic($resolved)
            ));
        }
        $obj = $resolved->toObject();
        if (self::CLASS_LC !== strtolower($obj->class->name)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($result) must be of type mysqli_result, %s given',
                $label,
                $obj->class->name
            ));
        }

        return $obj;
    }

    public static function wrap(Context $ctx, \mysqli_result $native): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('mysqli_result class not registered');
        }
        $entry = new ObjectEntry($class);
        $state = new MysqliResultState();
        $state->native = $native;
        self::$store[$entry->id] = $state;
        $entry->constructed = true;

        return $entry;
    }

    public static function state(ObjectEntry $entry): MysqliResultState
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('mysqli_result object has not been correctly initialized');
        }

        return self::$store[$entry->id];
    }

    public static function requireNative(ObjectEntry $entry): \mysqli_result
    {
        $state = self::state($entry);
        if (null === $state->native) {
            throw new \LogicException('mysqli_result is freed');
        }

        return $state->native;
    }

    public static function destroyState(ObjectEntry $entry): void
    {
        unset(self::$store[$entry->id]);
    }
}

/** @internal */
final class MysqliResultState
{
    public ?\mysqli_result $native = null;
}

final class MysqliConstruct extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::__construct()');
        if ($receiver->constructed) {
            throw new \LogicException('mysqli object already initialized');
        }
        $argc = \count($frame->calledArgs) - 1;
        $hostname = $argc >= 1 ? $this->stringArgNullable($frame->calledArgs[1]) : null;
        $username = $argc >= 2 ? $this->stringArgNullable($frame->calledArgs[2]) : null;
        $password = $argc >= 3 ? $this->stringArgNullable($frame->calledArgs[3]) : null;
        $database = $argc >= 4 ? $this->stringArgNullable($frame->calledArgs[4]) : null;
        $port = $argc >= 5 ? $this->intArgNullable($frame->calledArgs[5]) : null;
        $socket = $argc >= 6 ? $this->stringArgNullable($frame->calledArgs[6]) : null;

        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli requires VM context');

        if (!MysqliExtensionPolicy::hasNativeDriver()) {
            // No host ext/mysqli — mark object as constructed but with no native handle.
            // php-src: new mysqli() with no args returns an unconnected object;
            // with args and no driver it would throw mysqli_sql_exception.
            if ($argc > 0) {
                throw new \mysqli_sql_exception('mysqli extension not available on host PHP', 2002);
            }
            $receiver->constructed = true;

            return;
        }

        $hostname = $hostname ?? ini_get('mysqli.default_host') ?: '127.0.0.1';
        $username = $username ?? ini_get('mysqli.default_user') ?: '';
        $password = $password ?? ini_get('mysqli.default_pw') ?: '';
        $database = $database ?? '';
        $port = $port ?? (int) (ini_get('mysqli.default_port') ?: 3306);
        $socket = $socket ?? ini_get('mysqli.default_socket') ?: '';

        try {
            $native = @new \mysqli($hostname, $username, $password, $database, $port, $socket);
        } catch (\mysqli_sql_exception $e) {
            throw $e;
        }
        if ($native->connect_errno) {
            throw new \mysqli_sql_exception($native->connect_error ?? 'Connection error', $native->connect_errno);
        }

        $state = new MysqliState();
        $state->native = $native;
        $state->ctx = $ctx;
        VmMysqli::attachState($receiver, $state);
        VmMysqli::noteLinkOpened($state);
    }

    private function stringArgNullable(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }

    private function intArgNullable(Variable $var): ?int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toInt();
    }
}

final class MysqliQuery extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('query');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::query()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::query() expects at least 1 argument, 0 given');
        }
        $sql = $this->stringArg($frame->calledArgs[1], 'mysqli::query', 0, 'query');
        $resultMode = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'mysqli::query', 1, 'result_mode', MysqliConstants::MYSQLI_STORE_RESULT)
            : MysqliConstants::MYSQLI_STORE_RESULT;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::query() requires VM context');
        $native = VmMysqli::requireNative($receiver, $ctx);
        $result = $native->query($sql, $resultMode);
        if (null === $frame->returnVar) {
            return;
        }
        if (true === $result) {
            $frame->returnVar->bool(true);
        } elseif (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $ctx = VmMysqli::state($receiver)->ctx ?? throw new \LogicException('No VM context');
            $frame->returnVar->object(VmMysqliResult::wrap($ctx, $result));
        }
    }
}

/** mysqli::execute_query() — php-src ext/mysqli/mysqli_api.c (#21895). */
final class MysqliExecuteQuery extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('execute_query');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::execute_query()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::execute_query() expects at least 1 argument, 0 given');
        }
        $sql = $this->stringArg($frame->calledArgs[1], 'mysqli::execute_query', 0, 'query');
        $params = null;
        if (\count($frame->calledArgs) >= 3) {
            $params = VmMysqli::paramsListFromVariable(
                $frame->calledArgs[2],
                'mysqli::execute_query',
                1
            );
        }
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::execute_query() requires VM context');
        $result = VmMysqli::executeQueryOnLink($receiver, $sql, $params, $ctx, 'mysqli::execute_query', 2);
        if (null === $frame->returnVar) {
            return;
        }
        if (true === $result) {
            $frame->returnVar->bool(true);
        } elseif (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }
}

final class MysqliClose extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::close()');
        $state = VmMysqli::state($receiver);
        if (null !== $state->native) {
            $state->native->close();
            $state->native = null;
        }
        VmMysqli::noteLinkClosed($state);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class MysqliRealEscapeString extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('real_escape_string');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::real_escape_string()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::real_escape_string() expects exactly 1 argument, 0 given');
        }
        $str = $this->stringArg($frame->calledArgs[1], 'mysqli::real_escape_string', 0, 'string');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::real_escape_string() requires VM context');
        $native = VmMysqli::requireNative($receiver, $ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($native->real_escape_string($str));
        }
    }
}

final class MysqliPrepare extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('prepare');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::prepare()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::prepare() expects exactly 1 argument, 0 given');
        }
        $sql = $this->stringArg($frame->calledArgs[1], 'mysqli::prepare', 0, 'query');
        $result = VmMysqliStmt::prepareOnLink($receiver, $sql);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }
}

/** mysqli::stmt_init() — php-src ext/mysqli/mysqli.stub.php (#22215). */
final class MysqliStmtInit extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('stmt_init');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::stmt_init()');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmMysqliStmt::initOnLink($receiver);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }
}

/** mysqli::dump_debug_info() — php-src ext/mysqli/mysqli.stub.php (#22223). */
final class MysqliDumpDebugInfo extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('dump_debug_info');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::dump_debug_info()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::dump_debug_info() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::dumpDebugInfoOnLink($receiver, $ctx));
        }
    }
}

/** mysqli::get_warnings() — php-src ext/mysqli/mysqli.stub.php (#22224). */
final class MysqliGetWarnings extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('get_warnings');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::get_warnings()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::get_warnings() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $warning = VmMysqli::getWarningsOnLink($receiver, $ctx);
        if (null === $warning) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($warning);
        }
    }
}

final class MysqliAutocommit extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('autocommit');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::autocommit()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::autocommit() expects exactly 1 argument, 0 given');
        }
        $mode = $this->boolArg($frame->calledArgs[1], 'mysqli::autocommit', 0, 'mode');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::autocommit() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::autocommitOnLink($receiver, $ctx, $mode));
        }
    }

    private function boolArg(Variable $var, string $label, int $index, string $paramName): bool
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool();
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return 0 !== $resolved->toInt();
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return false;
        }

        return (bool) $resolved->toString();
    }
}

final class MysqliBeginTransaction extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('begin_transaction');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::begin_transaction()');
        $flags = \count($frame->calledArgs) >= 2
            ? $this->intArg($frame->calledArgs[1], 'mysqli::begin_transaction', 0, 'flags', 0)
            : 0;
        $name = \count($frame->calledArgs) >= 3
            ? $this->optionalStringArg($frame->calledArgs[2])
            : null;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::begin_transaction() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::beginTransactionOnLink($receiver, $ctx, $flags, $name));
        }
    }

    private function optionalStringArg(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}

final class MysqliCommit extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('commit');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::commit()');
        $flags = \count($frame->calledArgs) >= 2
            ? $this->intArg($frame->calledArgs[1], 'mysqli::commit', 0, 'flags', 0)
            : 0;
        $name = \count($frame->calledArgs) >= 3
            ? $this->optionalStringArg($frame->calledArgs[2])
            : null;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::commit() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::commitOnLink($receiver, $ctx, $flags, $name));
        }
    }

    private function optionalStringArg(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}

final class MysqliRollback extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('rollback');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::rollback()');
        $flags = \count($frame->calledArgs) >= 2
            ? $this->intArg($frame->calledArgs[1], 'mysqli::rollback', 0, 'flags', 0)
            : 0;
        $name = \count($frame->calledArgs) >= 3
            ? $this->optionalStringArg($frame->calledArgs[2])
            : null;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::rollback() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::rollbackOnLink($receiver, $ctx, $flags, $name));
        }
    }

    private function optionalStringArg(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}

final class MysqliSavepoint extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('savepoint');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::savepoint()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::savepoint() expects exactly 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'mysqli::savepoint', 0, 'name');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::savepoint() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::savepointOnLink($receiver, $ctx, $name));
        }
    }
}

final class MysqliReleaseSavepoint extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('release_savepoint');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::release_savepoint()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::release_savepoint() expects exactly 1 argument, 0 given');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'mysqli::release_savepoint', 0, 'name');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::release_savepoint() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::releaseSavepointOnLink($receiver, $ctx, $name));
        }
    }
}

final class MysqliRefresh extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('refresh');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::refresh()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::refresh() expects exactly 1 argument, 0 given');
        }
        $options = $this->intArg($frame->calledArgs[1], 'mysqli::refresh', 0, 'options', 0);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::refresh() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::refreshOnLink($receiver, $ctx, $options));
        }
    }
}

final class MysqliGetConnectionStats extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('get_connection_stats');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::get_connection_stats()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::get_connection_stats() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        VmMysqli::assignRow($frame->returnVar, VmMysqli::connectionStatsOnLink($receiver, $ctx));
    }
}

final class MysqliRealConnect extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('real_connect');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::real_connect()');
        $argc = \count($frame->calledArgs) - 1;
        $hostname = $argc >= 1 ? $this->optionalStringArg($frame->calledArgs[1]) : null;
        $username = $argc >= 2 ? $this->optionalStringArg($frame->calledArgs[2]) : null;
        $password = $argc >= 3 ? $this->optionalStringArg($frame->calledArgs[3]) : null;
        $database = $argc >= 4 ? $this->optionalStringArg($frame->calledArgs[4]) : null;
        $port = $argc >= 5 ? $this->intArg($frame->calledArgs[5], 'mysqli::real_connect', 4, 'port', 0) : null;
        $socket = $argc >= 6 ? $this->optionalStringArg($frame->calledArgs[6]) : null;
        $flags = $argc >= 7 ? $this->intArg($frame->calledArgs[7], 'mysqli::real_connect', 6, 'flags', 0) : 0;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::real_connect() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::realConnectOnLink(
                $receiver,
                $ctx,
                $hostname,
                $username,
                $password,
                $database,
                $port,
                $socket,
                $flags
            ));
        }
    }

    private function optionalStringArg(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}

final class MysqliOptions extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('options');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::options()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('mysqli::options() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given');
        }
        $option = $this->intArg($frame->calledArgs[1], 'mysqli::options', 0, 'option');
        $value = MysqliProceduralLink::optionValue($frame->calledArgs[2]);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::options() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::optionsOnLink($receiver, $ctx, $option, $value));
        }
    }
}

final class MysqliSetCharset extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('set_charset');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::set_charset()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::set_charset() expects exactly 1 argument, 0 given');
        }
        $charset = $this->stringArg($frame->calledArgs[1], 'mysqli::set_charset', 0, 'charset');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::set_charset() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::setCharsetOnLink($receiver, $ctx, $charset));
        }
    }
}

final class MysqliMultiQuery extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('multi_query');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::multi_query()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::multi_query() expects exactly 1 argument, 0 given');
        }
        $query = $this->stringArg($frame->calledArgs[1], 'mysqli::multi_query', 0, 'query');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::multi_query() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::multiQueryOnLink($receiver, $ctx, $query));
        }
    }
}

final class MysqliRealQuery extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('real_query');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::real_query()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::real_query() expects exactly 1 argument, 0 given');
        }
        $query = $this->stringArg($frame->calledArgs[1], 'mysqli::real_query', 0, 'query');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::real_query() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::realQueryOnLink($receiver, $ctx, $query));
        }
    }
}

final class MysqliNextResult extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('next_result');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::next_result()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::next_result() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::nextResultOnLink($receiver, $ctx));
        }
    }
}

final class MysqliMoreResults extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('more_results');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::more_results()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::more_results() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::moreResultsOnLink($receiver, $ctx));
        }
    }
}

final class MysqliStoreResult extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('store_result');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::store_result()');
        $flags = \count($frame->calledArgs) >= 2
            ? $this->intArg($frame->calledArgs[1], 'mysqli::store_result', 0, 'flags', 0)
            : 0;
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::store_result() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmMysqli::storeResultOnLink($receiver, $ctx, $flags);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }
}

final class MysqliUseResult extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('use_result');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::use_result()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::use_result() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmMysqli::useResultOnLink($receiver, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }
}

/** mysqli::poll() — static; php-src mysqli.stub.php (#22163). */
final class MysqliPoll extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('poll');
    }

    public function execute(Frame $frame): void
    {
        // Static: user args start at calledArgs[0] (lib/VM.php FLAG_STATIC; #22288).
        VmMysqli::executePoll($frame, 'mysqli::poll', 0);
    }
}

/** mysqli::reap_async_query() — php-src mysqli.stub.php (#22163). */
final class MysqliReapAsyncQuery extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('reap_async_query');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::reap_async_query()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::reap_async_query() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmMysqli::reapAsyncQueryOnLink($receiver, $ctx);
        if (true === $result) {
            $frame->returnVar->bool(true);
        } elseif (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }
}

final class MysqliInsertId extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('insert_id');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::insert_id()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::insert_id() requires VM context');
        if (null !== $frame->returnVar) {
            VmMysqli::assignInsertId($frame->returnVar, VmMysqli::insertIdOnLink($receiver, $ctx));
        }
    }
}

final class MysqliFieldCountMethod extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('field_count');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::field_count()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::field_count() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::fieldCountOnLink($receiver, $ctx));
        }
    }
}

final class MysqliSqlstateMethod extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('sqlstate');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::sqlstate()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::sqlstate() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqli::sqlstateOnLink($receiver, $ctx));
        }
    }
}

final class MysqliWarningCountMethod extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('warning_count');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::warning_count()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::warning_count() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::warningCountOnLink($receiver, $ctx));
        }
    }
}

final class MysqliCharacterSetName extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('character_set_name');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::character_set_name()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::character_set_name() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqli::characterSetNameOnLink($receiver, $ctx));
        }
    }
}

final class MysqliGetCharset extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('get_charset');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::get_charset()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::get_charset() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $charset = VmMysqli::getCharsetOnLink($receiver, $ctx);
        if (null === $charset) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($charset);
        }
    }
}

final class MysqliGetServerInfo extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('get_server_info');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::get_server_info()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::get_server_info() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqli::serverInfoOnLink($receiver, $ctx));
        }
    }
}

final class MysqliGetHostInfo extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('get_host_info');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::get_host_info()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::get_host_info() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqli::hostInfoOnLink($receiver, $ctx));
        }
    }
}

final class MysqliGetProtoInfo extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('get_proto_info');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::get_proto_info()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::get_proto_info() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::protoInfoOnLink($receiver, $ctx));
        }
    }
}

final class MysqliGetServerVersion extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('get_server_version');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::get_server_version()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::get_server_version() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::serverVersionOnLink($receiver, $ctx));
        }
    }
}

final class MysqliGetClientInfoMethod extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('get_client_info');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::get_client_info()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::get_client_info() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqli::clientInfoOnLink($receiver, $ctx));
        }
    }
}

final class MysqliSslSet extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('ssl_set');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::ssl_set()');
        if (\count($frame->calledArgs) < 6) {
            throw new \ArgumentCountError('mysqli::ssl_set() expects exactly 5 arguments, '.(\count($frame->calledArgs) - 1).' given');
        }
        $key = $this->nullableStringArg($frame->calledArgs[1]);
        $certificate = $this->nullableStringArg($frame->calledArgs[2]);
        $caCertificate = $this->nullableStringArg($frame->calledArgs[3]);
        $caPath = $this->nullableStringArg($frame->calledArgs[4]);
        $cipherAlgos = $this->nullableStringArg($frame->calledArgs[5]);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::ssl_set() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::sslSetOnLink($receiver, $ctx, $key, $certificate, $caCertificate, $caPath, $cipherAlgos));
        }
    }

    private function nullableStringArg(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}

/** mysqli::ping() — php-src ext/mysqli/mysqli.stub.php (#22174). */
final class MysqliPing extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('ping');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::ping()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::ping() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::pingOnLink($receiver, $ctx));
        }
    }
}

/** mysqli::select_db() — php-src ext/mysqli/mysqli.stub.php (#22174). */
final class MysqliSelectDb extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('select_db');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::select_db()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::select_db() expects exactly 1 argument, 0 given');
        }
        $database = $this->stringArg($frame->calledArgs[1], 'mysqli::select_db', 0, 'database');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::select_db() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::selectDbOnLink($receiver, $ctx, $database));
        }
    }
}

/** mysqli::change_user() — php-src ext/mysqli/mysqli.stub.php (#22174). */
final class MysqliChangeUser extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('change_user');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::change_user()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError('mysqli::change_user() expects exactly 3 arguments, '.(\count($frame->calledArgs) - 1).' given');
        }
        $username = $this->stringArg($frame->calledArgs[1], 'mysqli::change_user', 0, 'username');
        $password = $this->stringArg($frame->calledArgs[2], 'mysqli::change_user', 1, 'password');
        $database = $this->nullableStringArg($frame->calledArgs[3]);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::change_user() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::changeUserOnLink($receiver, $ctx, $username, $password, $database));
        }
    }

    private function nullableStringArg(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}

/**
 * mysqli::thread_id() — getter mirror of $thread_id (#22174).
 *
 * php-src exposes readonly $thread_id; method form matches insert_id()/field_count() pattern in this VM.
 */
final class MysqliThreadId extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('thread_id');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::thread_id()');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::thread_id() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::threadIdOnLink($receiver, $ctx));
        }
    }
}

/** mysqli::kill() — php-src ext/mysqli/mysqli.stub.php (#22174). */
final class MysqliKill extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('kill');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli::kill()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli::kill() expects exactly 1 argument, 0 given');
        }
        $processId = $this->intArg($frame->calledArgs[1], 'mysqli::kill', 0, 'process_id');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli::kill() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqli::killOnLink($receiver, $ctx, $processId));
        }
    }
}

final class MysqliResultFetchAssoc extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_assoc');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_assoc()');
        $native = VmMysqliResult::requireNative($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        $row = $native->fetch_assoc();
        if (null === $row) {
            $frame->returnVar->null();
        } else {
            VmMysqli::assignRow($frame->returnVar, $row);
        }
    }
}

final class MysqliResultFetchArray extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_array');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_array()');
        $native = VmMysqliResult::requireNative($receiver);
        $mode = MysqliConstants::MYSQLI_BOTH;
        if (\count($frame->calledArgs) >= 2) {
            $mode = $this->intArg($frame->calledArgs[1], 'mysqli_result::fetch_array', 0, 'mode', MysqliConstants::MYSQLI_BOTH);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $row = match ($mode) {
            MysqliConstants::MYSQLI_ASSOC => $native->fetch_assoc(),
            MysqliConstants::MYSQLI_NUM => $native->fetch_row(),
            default => $native->fetch_array(\MYSQLI_BOTH),
        };
        if (null === $row) {
            $frame->returnVar->null();
        } else {
            VmMysqli::assignRow($frame->returnVar, $row);
        }
    }
}

final class MysqliResultFetchRow extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_row');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_row()');
        $native = VmMysqliResult::requireNative($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        $row = $native->fetch_row();
        if (null === $row) {
            $frame->returnVar->null();
        } else {
            VmMysqli::assignRow($frame->returnVar, $row);
        }
    }
}

/** mysqli_result::fetch_column() — php-src ext/mysqli/mysqli_nonapi.c (#22214). */
final class MysqliResultFetchColumn extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_column');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_column()');
        $native = VmMysqliResult::requireNative($receiver);
        $column = 0;
        if (\count($frame->calledArgs) >= 2) {
            $column = $this->intArg($frame->calledArgs[1], 'mysqli_result::fetch_column', 0, 'column', 0);
        }
        if (null === $frame->returnVar) {
            return;
        }
        VmMysqliResult::assignFetchColumnResult(
            $frame->returnVar,
            VmMysqliResult::fetchColumn($native, $column, 'mysqli_result::fetch_column', 1)
        );
    }
}

final class MysqliResultFetchAll extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_all');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_all()');
        $native = VmMysqliResult::requireNative($receiver);
        $mode = MysqliConstants::MYSQLI_NUM;
        if (\count($frame->calledArgs) >= 2) {
            $mode = $this->intArg($frame->calledArgs[1], 'mysqli_result::fetch_all', 0, 'mode', MysqliConstants::MYSQLI_NUM);
        }
        if (null === $frame->returnVar) {
            return;
        }
        VmMysqliResult::assignRows($frame->returnVar, VmMysqliResult::fetchAllRows($native, $mode));
    }
}

final class MysqliResultFetchObject extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_object');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_object()');
        $native = VmMysqliResult::requireNative($receiver);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_result::fetch_object() requires VM context');
        $class = 'stdClass';
        $ctorArgs = [];
        if (\count($frame->calledArgs) >= 2) {
            $class = $this->stringArg($frame->calledArgs[1], 'mysqli_result::fetch_object', 0, 'class');
        }
        if (\count($frame->calledArgs) >= 3) {
            $ctorVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $ctorVar->type) {
                foreach ($ctorVar->toArray()->iterate(true) as $itemVar) {
                    $ctorArgs[] = match ($itemVar->type) {
                        Variable::TYPE_NULL => null,
                        Variable::TYPE_BOOLEAN => $itemVar->toBool(),
                        Variable::TYPE_INTEGER => $itemVar->toInt(),
                        Variable::TYPE_FLOAT => $itemVar->toFloat(),
                        default => $itemVar->toString(),
                    };
                }
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $obj = VmMysqliResult::fetchObject($ctx, $native, $class, $ctorArgs);
        if (null === $obj) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->object($obj);
        }
    }
}

final class MysqliResultFetchField extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_field');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_field()');
        $native = VmMysqliResult::requireNative($receiver);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_result::fetch_field() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $field = VmMysqliResult::fetchField($native, $ctx);
        if (null === $field) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->object($field);
        }
    }
}

final class MysqliResultFetchFields extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_fields');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_fields()');
        $native = VmMysqliResult::requireNative($receiver);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_result::fetch_fields() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        foreach (VmMysqliResult::fetchFields($native, $ctx) as $i => $field) {
            $slot = new Variable();
            $slot->object($field);
            $ht->add((string) $i, $slot);
        }
        $frame->returnVar->array($ht);
    }
}

final class MysqliResultFetchFieldDirect extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_field_direct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_field_direct()');
        $native = VmMysqliResult::requireNative($receiver);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_result::fetch_field_direct() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_result::fetch_field_direct() expects exactly 1 argument, 0 given');
        }
        $index = $this->intArg($frame->calledArgs[1], 'mysqli_result::fetch_field_direct', 0, 'index');
        if (null === $frame->returnVar) {
            return;
        }
        $field = VmMysqliResult::fetchFieldDirect($native, $ctx, $index);
        if (null === $field) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($field);
        }
    }
}

final class MysqliResultFetchLengths extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('fetch_lengths');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::fetch_lengths()');
        $native = VmMysqliResult::requireNative($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        $lengths = VmMysqliResult::fetchLengths($native);
        if (null === $lengths) {
            $frame->returnVar->bool(false);
        } else {
            $ht = new HashTable();
            foreach ($lengths as $i => $len) {
                $slot = new Variable();
                $slot->int($len);
                $ht->add((string) $i, $slot);
            }
            $frame->returnVar->array($ht);
        }
    }
}

final class MysqliResultDataSeek extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('data_seek');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::data_seek()');
        $native = VmMysqliResult::requireNative($receiver);
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_result::data_seek() expects exactly 1 argument, 0 given');
        }
        $offset = $this->intArg($frame->calledArgs[1], 'mysqli_result::data_seek', 0, 'offset');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($native->data_seek($offset));
        }
    }
}

final class MysqliResultFieldSeek extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('field_seek');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::field_seek()');
        $native = VmMysqliResult::requireNative($receiver);
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_result::field_seek() expects exactly 1 argument, 0 given');
        }
        $index = $this->intArg($frame->calledArgs[1], 'mysqli_result::field_seek', 0, 'index');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($native->field_seek($index));
        }
    }
}

final class MysqliResultFieldTell extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('field_tell');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::field_tell()');
        $native = VmMysqliResult::requireNative($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($native->current_field);
        }
    }
}

final class MysqliResultFree extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('free');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_result::free()');
        $state = VmMysqliResult::state($receiver);
        if (null !== $state->native) {
            $state->native->free();
            $state->native = null;
        }
    }
}
