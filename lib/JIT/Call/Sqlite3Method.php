<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * SQLite3 thin-AOT methods — __construct / exec / querySingle / close /
 * lastInsertRowID / changes / lastErrorCode / lastErrorMsg / busyTimeout /
 * enableExceptions / escapeString / version / open / prepare / query
 * (#35931 leftover of #35914; lastError leftover #35966; busyTimeout leftover #35972;
 * enableExceptions leftover #35975; escapeString leftover #35977;
 * version leftover #35991; open leftover #36001; prepare/query leftover #36010).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\sqlite3} (#36204). php-src: ext/sqlite3/sqlite3.c
 */
final class Sqlite3Method implements Call
{
    public string $name;

    /** @var list<string> */
    public array $paramNames = [];

    public int $namedArgsReceiverPrefix = 1;

    public function __construct(
        private readonly string $method,
    ) {
        $this->name = 'SQLite3::'.$method;
        $lc = strtolower($method);
        if ('__construct' === $lc) {
            $this->paramNames = ['filename', 'flags=', 'encryption_key='];
        } elseif ('exec' === $lc) {
            $this->paramNames = ['query'];
        } elseif ('querysingle' === $lc) {
            $this->paramNames = ['query', 'entireRow='];
        } elseif ('lastinsertrowid' === $lc || 'changes' === $lc
            || 'lasterrorcode' === $lc || 'lasterrormsg' === $lc) {
            $this->paramNames = [];
        } elseif ('busytimeout' === $lc) {
            $this->paramNames = ['milliseconds'];
        } elseif ('enableexceptions' === $lc) {
            $this->paramNames = ['enable='];
        } elseif ('escapestring' === $lc) {
            // Static or instance — positional string; no implicit $this for static (#35977).
            $this->paramNames = ['string'];
            $this->namedArgsReceiverPrefix = 0;
        } elseif ('version' === $lc) {
            // Static ZEND_METHOD — no implicit $this for SQLite3::version() (#35991).
            $this->paramNames = [];
            $this->namedArgsReceiverPrefix = 0;
        } elseif ('open' === $lc) {
            $this->paramNames = ['filename', 'flags=', 'encryption_key='];
        } elseif ('prepare' === $lc || 'query' === $lc) {
            $this->paramNames = ['query'];
        }
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireSqlite3()->sqlite3Method(
            $context,
            $this->method,
            ...$args
        );
    }
}
