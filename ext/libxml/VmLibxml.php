<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * Internal libxml error ring buffer (php-src ext/libxml/libxml.c; issue #6058).
 *
 * PHP-in-PHP: no runtime/*.c growth — DOM/SimpleXML loaders call {@see self::handleError()}.
 */
final class VmLibxml
{
    public const CLASS_LC = 'libxmlerror';

    private static ?Variable $streamsContext = null;

    private static bool $entityLoaderDisabled = false;

    private static ?Variable $externalEntityLoader = null;

    /** Pinned separately — VM GC may clear ObjectEntry::$closureState when only a PHP static holds the Variable (#21599). */
    private static ?\PHPCompiler\VM\ClosureState $externalEntityLoaderState = null;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $intProto = new Variable(Variable::TYPE_INTEGER);
        $strProto = new Variable(Variable::TYPE_STRING);
        $entry = new ClassEntry('LibXMLError');
        $entry->isInternal = true;
        $entry->properties[] = new ClassProperty('level', null, $intProto);
        $entry->properties[] = new ClassProperty('code', null, $intProto);
        $entry->properties[] = new ClassProperty('column', null, $intProto);
        $entry->properties[] = new ClassProperty('message', null, $strProto);
        $entry->properties[] = new ClassProperty('file', null, $strProto);
        $entry->properties[] = new ClassProperty('line', null, $intProto);
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function useInternalErrors(?bool $useErrors): bool
    {
        return LibxmlInternalErrorsJitHelper::exchange(null !== $useErrors, $useErrors ?? false);
    }

    public static function usingInternalErrors(): bool
    {
        return LibxmlInternalErrorsJitHelper::using();
    }

    /** php-src ext/libxml/libxml.c — libxml_set_streams_context() global IO context (#14495). */
    public static function setStreamsContext(Variable $context): void
    {
        self::$streamsContext = $context->resolveIndirect();
    }

    public static function streamsContext(): ?Variable
    {
        return self::$streamsContext;
    }

    /** php-src ext/libxml/libxml.c — php_libxml_disable_entity_loader() (#6379). */
    public static function disableEntityLoader(bool $disable = true): bool
    {
        $previous = self::$entityLoaderDisabled;
        self::$entityLoaderDisabled = $disable;

        return $previous;
    }

    public static function entityLoaderDisabled(): bool
    {
        return self::$entityLoaderDisabled;
    }

    /** php-src ext/libxml/libxml.c — libxml_set_external_entity_loader() (#6379, #14953, #21599). */
    public static function setExternalEntityLoader(Context $ctx, Variable $resolver): void
    {
        $resolved = $resolver->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            self::$externalEntityLoader = null;
            self::$externalEntityLoaderState = null;

            return;
        }
        if (!VmCallable::isCallable($ctx, $resolver)) {
            throw new \TypeError(
                'libxml_set_external_entity_loader(): Argument #1 ($resolver_function) must be a valid callback'
            );
        }
        $stored = new Variable();
        $stored->copyFrom($resolved);
        self::$externalEntityLoader = $stored;
        // Pin ClosureState: temp/inline closures can lose ObjectEntry::$closureState after the
        // creating frame is GC'd while only this PHP static still references the Variable.
        self::$externalEntityLoaderState = VmClosureCall::isClosure($stored)
            ? VmClosureCall::resolve($stored)
            : null;
    }

    public static function getExternalEntityLoader(): ?Variable
    {
        self::rebindExternalEntityLoaderClosureState();

        return self::$externalEntityLoader;
    }

    /** Re-attach pinned ClosureState after VM GC nulls ObjectEntry::$closureState (#21599). */
    private static function rebindExternalEntityLoaderClosureState(): void
    {
        if (null === self::$externalEntityLoader || null === self::$externalEntityLoaderState) {
            return;
        }
        $resolved = self::$externalEntityLoader->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return;
        }
        $object = $resolved->toObject();
        if (null === $object->closureState) {
            $object->closureState = self::$externalEntityLoaderState;
        }
    }

    /**
     * Resolve an external general entity for DOM/SimpleXML NOENT substitution.
     *
     * php-src ext/libxml/libxml.c — php_libxml_external_entity_loader / default input buffer (#21599).
     *
     * @return string|null entity body, or null when load failed (error already recorded)
     */
    public static function resolveExternalEntityContent(
        Context $ctx,
        ?string $publicId,
        string $systemId,
        ?Frame $frame = null
    ): ?string {
        $loader = self::$externalEntityLoader;
        if (null !== $loader) {
            $publicVar = new Variable();
            if (null === $publicId) {
                $publicVar->null();
            } else {
                $publicVar->string($publicId);
            }
            $systemVar = new Variable();
            $systemVar->string($systemId);
            $contextVar = self::externalEntityLoaderContextVariable();
            self::rebindExternalEntityLoaderClosureState();
            if (null !== self::$externalEntityLoaderState) {
                $result = VmClosureCall::invoke(
                    $ctx,
                    self::$externalEntityLoaderState,
                    $publicVar,
                    $systemVar,
                    $contextVar
                );
            } else {
                $result = VmCallable::invoke($ctx, $loader, $publicVar, $systemVar, $contextVar);
            }
            $content = self::materializeExternalEntityLoaderResult($ctx, $result);
            if (null === $content) {
                // php-src ext/libxml/libxml.c — php_libxml_external_entity_loader (#29596):
                // public ID NULL → "because the resolver function returned null"; else quote the ID.
                $message = null === $publicId
                    ? 'Failed to load external entity because the resolver function returned null'
                    : sprintf('Failed to load external entity "%s"', $publicId);
                self::handleError(
                    $ctx,
                    [
                        'level' => LibxmlConstants::LIBXML_ERR_ERROR,
                        'code' => 1,
                        'column' => 0,
                        'message' => $message,
                        'file' => '',
                        'line' => 0,
                    ],
                    $frame,
                    null,
                    $message
                );

                return null;
            }

            return $content;
        }

        if (self::$entityLoaderDisabled) {
            self::reportDefaultExternalEntityLoadFailure($ctx, $systemId, $frame);

            return null;
        }

        $data = VmFs::readPathContentsViaOpen($systemId, $ctx);
        if (false === $data) {
            self::reportDefaultExternalEntityLoadFailure($ctx, $systemId, $frame);

            return null;
        }

        return $data;
    }

    /** php-src entity_loader $context — directory/intSubName/extSubURI/extSubSystem (#21599). */
    private static function externalEntityLoaderContextVariable(): Variable
    {
        $ht = new HashTable();
        foreach (['directory', 'intSubName', 'extSubURI', 'extSubSystem'] as $key) {
            $nullVar = new Variable();
            $nullVar->null();
            $ht->add($key, $nullVar);
        }
        $var = new Variable();
        $var->array($ht);

        return $var;
    }

    /**
     * Loader return: resource stream → contents; string → path/URI to open; null/false → fail.
     */
    private static function materializeExternalEntityLoaderResult(Context $ctx, Variable $result): ?string
    {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_NULL === $result->type
            || (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool())
        ) {
            return null;
        }
        if (ResourceSupport::isOpenStreamResource($result)) {
            $handle = ResourceSupport::resolveHandle($result);
            if (null === $handle) {
                return null;
            }
            $data = VmFs::streamGetContents($handle);
            if (false === $data) {
                return null;
            }

            return $data;
        }
        if (Variable::TYPE_STRING === $result->type) {
            $path = $result->toString();
            if ('' === $path) {
                return null;
            }
            $data = VmFs::readPathContentsViaOpen($path, $ctx);

            return false === $data ? null : $data;
        }

        return null;
    }

    /** Default / disable_entity_loader failure — libxml I/O warning code 1549. */
    private static function reportDefaultExternalEntityLoadFailure(
        Context $ctx,
        string $systemId,
        ?Frame $frame
    ): void {
        $message = sprintf('failed to load external entity "%s"', $systemId);
        self::handleError(
            $ctx,
            [
                'level' => LibxmlConstants::LIBXML_ERR_WARNING,
                'code' => 1549,
                'column' => 0,
                'message' => $message,
                'file' => '',
                'line' => 0,
            ],
            $frame,
            null,
            'I/O warning : '.$message
        );
    }

    public static function resetEntityLoaderStateForTest(): void
    {
        self::$entityLoaderDisabled = false;
        self::$externalEntityLoader = null;
        self::$externalEntityLoaderState = null;
    }

    public static function getErrors(Context $ctx): HashTable
    {
        $ht = new HashTable();
        foreach (LibxmlInternalErrorsJitHelper::records() as $record) {
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
        $errors = LibxmlInternalErrorsJitHelper::records();
        if ([] === $errors) {
            $var->bool(false);

            return $var;
        }
        $var->copyFrom(self::createErrorObject($ctx, $errors[\count($errors) - 1]));

        return $var;
    }

    public static function clearErrors(): void
    {
        LibxmlInternalErrorsJitHelper::clear();
    }

    /**
     * php-src ext/xml/xml.c — expat failures populate libxml ring without php_error().
     *
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int} $record
     */
    public static function recordError(array $record): void
    {
        LibxmlInternalErrorsJitHelper::record($record);
    }

    /**
     * @param array{level: int, code: int, column: int, message: string, file: string, line: int} $record
     */
    public static function handleError(
        Context $ctx,
        array $record,
        ?Frame $frame = null,
        ?string $file = null,
        ?string $warningMessage = null
    ): void {
        if (LibxmlInternalErrorsJitHelper::using()) {
            LibxmlInternalErrorsJitHelper::record($record);

            return;
        }

        // libxml record line is XML entity line; PHP warning cites user call site via Frame (#11163, #15140).
        $warningLine = null !== $frame ? 0 : $record['line'];
        $ctx->errors->languageWarning(
            $warningMessage ?? $record['message'],
            '' !== $record['file'] ? $record['file'] : $file,
            $warningLine,
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
