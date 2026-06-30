<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Internal libxml error ring buffer (php-src ext/libxml/libxml.c; issue #6058).
 *
 * PHP-in-PHP: no runtime/*.c growth — DOM/SimpleXML loaders call {@see self::handleError()}.
 */
final class VmLibxml
{
    public const CLASS_LC = 'libxmlerror';

    private static bool $useInternalErrors = false;

    /** @var list<array{level: int, code: int, column: int, message: string, file: string, line: int}> */
    private static array $errors = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $intProto = new Variable(Variable::TYPE_INTEGER);
        $strProto = new Variable(Variable::TYPE_STRING);
        $entry = new ClassEntry('LibXMLError');
        $entry->isInternal = true;
        $entry->properties[] = new ClassProperty('level', null, $intProto, true);
        $entry->properties[] = new ClassProperty('code', null, $intProto, true);
        $entry->properties[] = new ClassProperty('column', null, $intProto, true);
        $entry->properties[] = new ClassProperty('message', null, $strProto, true);
        $entry->properties[] = new ClassProperty('file', null, $strProto, true);
        $entry->properties[] = new ClassProperty('line', null, $intProto, true);
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function useInternalErrors(?bool $useErrors): bool
    {
        $previous = self::$useInternalErrors;
        if (null !== $useErrors) {
            self::$useInternalErrors = $useErrors;
        }

        return $previous;
    }

    public static function getErrors(Context $ctx): HashTable
    {
        $ht = new HashTable();
        foreach (self::$errors as $record) {
            $ht->append(self::createErrorObject($ctx, $record));
        }

        return $ht;
    }

    /**
     * php-src libxml_get_last_error() — tail of the internal error buffer, or false when empty.
     */
    public static function getLastError(Context $ctx): Variable
    {
        $var = new Variable();
        if ([] === self::$errors) {
            $var->bool(false);

            return $var;
        }
        $var->copyFrom(self::createErrorObject($ctx, self::$errors[\count(self::$errors) - 1]));

        return $var;
    }

    public static function clearErrors(): void
    {
        self::$errors = [];
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int} $record
     */
    public static function handleError(
        Context $ctx,
        array $record,
        ?Frame $frame = null,
        ?string $file = null
    ): void {
        if (self::$useInternalErrors) {
            self::$errors[] = $record;

            return;
        }

        $ctx->errors->languageWarning(
            $record['message'],
            '' !== $record['file'] ? $record['file'] : $file,
            $record['line'],
            $ctx,
            $frame
        );
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int} $record
     */
    public static function createErrorObject(Context $ctx, array $record): Variable
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('LibXMLError is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty('level')->int($record['level']);
        $entry->getProperty('code')->int($record['code']);
        $entry->getProperty('column')->int($record['column']);
        $entry->getProperty('message')->string($record['message']);
        $entry->getProperty('file')->string($record['file']);
        $entry->getProperty('line')->int($record['line']);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }
}
