<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlsrv;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Shared sqlsrv_* semantics (php-src ext/sqlsrv; #6577).
 */
final class VmSqlsrvCore
{
    /** @var list<array{state: string, code: int, message: string}> */
    private static array $errors = [];

    public static function clearErrors(): void
    {
        self::$errors = [];
    }

    public static function pushError(string $state, int $code, string $message): void
    {
        self::$errors[] = [
            'state' => $state,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * @return list<array{state: string, code: int, message: string}>
     */
    public static function peekErrors(): array
    {
        return self::$errors;
    }

    public static function connect(string $serverName, array $connectionInfo, Context $ctx): Variable|false
    {
        self::clearErrors();
        if (!SqlsrvExtensionPolicy::hasNativeDriver()) {
            self::pushError(
                'IMSSP',
                -49,
                'This extension requires the Microsoft ODBC Driver for SQL Server to communicate with SQL Server'
            );

            return false;
        }

        $native = \sqlsrv_connect($serverName, $connectionInfo);
        if (false === $native) {
            self::importHostErrors();

            return false;
        }

        return VmSqlsrvConnection::wrap($native, $ctx);
    }

    public static function importHostErrors(): void
    {
        self::$errors = [];
        $hostErrors = \sqlsrv_errors();
        if (!\is_array($hostErrors)) {
            return;
        }
        foreach ($hostErrors as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $state = (string) ($row['SQLSTATE'] ?? $row[0] ?? 'HY000');
            $code = (int) ($row['code'] ?? $row[1] ?? 0);
            $message = (string) ($row['message'] ?? $row[2] ?? '');
            self::pushError($state, $code, $message);
        }
    }

    public static function buildErrorsVariable(Variable $returnVar): void
    {
        if ([] === self::$errors) {
            $returnVar->null();

            return;
        }
        $outer = new HashTable();
        $index = 0;
        foreach (self::$errors as $err) {
            $row = new HashTable();
            $v0 = new Variable();
            $v0->string($err['state']);
            $row->add('0', $v0);
            $vState = new Variable();
            $vState->string($err['state']);
            $row->add('SQLSTATE', $vState);
            $v1 = new Variable();
            $v1->int($err['code']);
            $row->add('1', $v1);
            $vCode = new Variable();
            $vCode->int($err['code']);
            $row->add('code', $vCode);
            $v2 = new Variable();
            $v2->string($err['message']);
            $row->add('2', $v2);
            $vMsg = new Variable();
            $vMsg->string($err['message']);
            $row->add('message', $vMsg);
            $slot = new Variable();
            $slot->array($row);
            $outer->add((string) $index, $slot);
            ++$index;
        }
        $returnVar->array($outer);
    }

    /**
     * @param array<int|string, mixed> $connectionInfo
     */
    public static function coerceConnectionInfo(Variable $var, string $fn): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return [];
        }
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #2 ($connectionInfo) must be of type array, %s given',
                $fn,
                match ($var->type) {
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }
            ));
        }
        $out = [];
        foreach ($var->toArray()->iterateKeyed(true) as [$keyVar, $slot]) {
            $key = $keyVar->toString();
            if (Variable::TYPE_STRING === $slot->type) {
                $out[$key] = $slot->toString();
            } elseif (Variable::TYPE_INTEGER === $slot->type) {
                $out[$key] = $slot->toInt();
            } elseif (Variable::TYPE_BOOLEAN === $slot->type) {
                $out[$key] = $slot->toBool();
            } elseif (Variable::TYPE_NULL === $slot->type) {
                $out[$key] = null;
            } else {
                $out[$key] = $slot->toString();
            }
        }

        return $out;
    }
}
