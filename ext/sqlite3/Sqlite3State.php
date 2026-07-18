<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/** Per-instance sqlite3 handle state (php-src ext/sqlite3/sqlite3.c; issue #3434). */
final class Sqlite3State
{
    /** @var \FFI\CData|null sqlite3* */
    public ?\FFI\CData $db = null;

    public bool $closed = false;

    public string $filename = '';

    /** php-src sqlite3_db.exceptions (#19862). Default off. */
    public bool $exceptions = false;

    /**
     * Registered scalar UDFs (name lc => entry).
     *
     * @var array<string, array{callback: \PHPCompiler\VM\Variable, closure: ?\PHPCompiler\VM\ClosureState, argc: int, ctx: \PHPCompiler\VM\Context}>
     */
    public array $functions = [];

    /**
     * Registered collations (name lc => entry) — php-src createCollation (#20565).
     *
     * @var array<string, array{callback: \PHPCompiler\VM\Variable, closure: ?\PHPCompiler\VM\ClosureState, ctx: \PHPCompiler\VM\Context}>
     */
    public array $collations = [];

    /**
     * Registered aggregates (name lc => entry) — php-src createAggregate (#20585).
     *
     * @var array<string, array{
     *     step: \PHPCompiler\VM\Variable,
     *     stepClosure: ?\PHPCompiler\VM\ClosureState,
     *     final: \PHPCompiler\VM\Variable,
     *     finalClosure: ?\PHPCompiler\VM\ClosureState,
     *     argc: int,
     *     ctx: \PHPCompiler\VM\Context
     * }>
     */
    public array $aggregates = [];

    /**
     * Authorizer callback (php-src setAuthorizer; #20683).
     *
     * @var \PHPCompiler\VM\Variable|null
     */
    public $authorizer = null;

    /** @var \PHPCompiler\VM\ClosureState|null */
    public $authorizerClosure = null;

    /** @var \PHPCompiler\VM\Context|null */
    public $authorizerCtx = null;
}
