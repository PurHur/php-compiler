<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\VM\Variable;

/**
 * Soft authorizer gate before prepare/exec (php-src sqlite3_set_authorizer; #20683).
 *
 * Invokes the userland callback for the primary statement action. Full per-opcode
 * libpq FFI authorizer remains a follow-up; this matches Zend for allow/deny of
 * CREATE/INSERT/SELECT/DROP style statements used in compliance.
 */
final class VmSqlite3Authorizer
{
    /**
     * @return bool false when authorizer denies (caller should fail the statement)
     */
    public static function allow(Sqlite3State $state, string $sql): bool
    {
        if (null === $state->authorizer || null === $state->authorizerCtx) {
            return true;
        }
        $action = self::inferAction($sql);
        if (null === $action) {
            return true;
        }
        $actionVar = new Variable(Variable::TYPE_INTEGER);
        $actionVar->int($action);
        $nulls = [];
        for ($i = 0; $i < 4; ++$i) {
            $n = new Variable();
            $n->null();
            $nulls[] = $n;
        }
        try {
            $ret = VmCallable::invokeAs(
                'SQLite3::setAuthorizer',
                $state->authorizerCtx,
                $state->authorizer,
                $actionVar,
                ...$nulls
            );
        } catch (\Throwable $e) {
            if ($state->exceptions) {
                throw $e;
            }
            @\trigger_error('An error occurred while invoking the authorizer callback', \E_USER_WARNING);

            return false;
        }
        $resolved = $ret->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $resolved->type
            && Variable::TYPE_FLOAT !== $resolved->type
            && Variable::TYPE_BOOLEAN !== $resolved->type
        ) {
            @\trigger_error('The authorizer callback returned an invalid type: expected int', \E_USER_WARNING);

            return false;
        }
        $code = (int) $resolved->toInt();
        if (Sqlite3Constants::OK !== $code
            && Sqlite3Constants::IGNORE !== $code
            && Sqlite3Constants::DENY !== $code
        ) {
            @\trigger_error('The authorizer callback returned an invalid value: '.$code, \E_USER_WARNING);

            return false;
        }
        if (Sqlite3Constants::DENY === $code) {
            return false;
        }

        return true;
    }

    private static function inferAction(string $sql): ?int
    {
        $trim = ltrim($sql);
        if ('' === $trim) {
            return null;
        }
        if (1 === preg_match('/^create\s+temp(?:orary)?\s+table\b/i', $trim)) {
            return Sqlite3Constants::CREATE_TEMP_TABLE;
        }
        if (1 === preg_match('/^create\s+table\b/i', $trim)) {
            return Sqlite3Constants::CREATE_TABLE;
        }
        if (1 === preg_match('/^create\s+temp(?:orary)?\s+view\b/i', $trim)) {
            return Sqlite3Constants::CREATE_TEMP_VIEW;
        }
        if (1 === preg_match('/^create\s+view\b/i', $trim)) {
            return Sqlite3Constants::CREATE_VIEW;
        }
        if (1 === preg_match('/^create\s+temp(?:orary)?\s+index\b/i', $trim)) {
            return Sqlite3Constants::CREATE_TEMP_INDEX;
        }
        if (1 === preg_match('/^create\s+(?:unique\s+)?index\b/i', $trim)) {
            return Sqlite3Constants::CREATE_INDEX;
        }
        if (1 === preg_match('/^drop\s+table\b/i', $trim)) {
            return Sqlite3Constants::DROP_TABLE;
        }
        if (1 === preg_match('/^drop\s+view\b/i', $trim)) {
            return Sqlite3Constants::DROP_VIEW;
        }
        if (1 === preg_match('/^drop\s+index\b/i', $trim)) {
            return Sqlite3Constants::DROP_INDEX;
        }
        if (1 === preg_match('/^insert\b/i', $trim)) {
            return Sqlite3Constants::INSERT;
        }
        if (1 === preg_match('/^update\b/i', $trim)) {
            return Sqlite3Constants::UPDATE;
        }
        if (1 === preg_match('/^delete\b/i', $trim)) {
            return Sqlite3Constants::DELETE;
        }
        if (1 === preg_match('/^select\b/i', $trim)) {
            return Sqlite3Constants::SELECT;
        }
        if (1 === preg_match('/^pragma\b/i', $trim)) {
            return Sqlite3Constants::PRAGMA;
        }
        if (1 === preg_match('/^attach\b/i', $trim)) {
            return Sqlite3Constants::ATTACH;
        }
        if (1 === preg_match('/^detach\b/i', $trim)) {
            return Sqlite3Constants::DETACH;
        }
        if (1 === preg_match('/^alter\s+table\b/i', $trim)) {
            return Sqlite3Constants::ALTER_TABLE;
        }
        if (1 === preg_match('/^begin\b|^commit\b|^rollback\b|^end\b/i', $trim)) {
            return Sqlite3Constants::TRANSACTION;
        }

        return null;
    }
}
