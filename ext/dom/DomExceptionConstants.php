<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * DOMException error codes (php-src ext/dom/domexception.c, dom_ce.h; issue #15430).
 */
final class DomExceptionConstants
{
    public const INDEX_SIZE_ERR = 1;

    public const STRING_SIZE_ERR = 2;

    public const HIERARCHY_REQUEST_ERR = 3;

    public const WRONG_DOCUMENT_ERR = 4;

    public const INVALID_CHARACTER_ERR = 5;

    public const NO_DATA_ALLOWED_ERR = 6;

    public const NO_MODIFICATION_ALLOWED_ERR = 7;

    public const NOT_FOUND_ERR = 8;

    public const NOT_SUPPORTED_ERR = 9;

    public const INUSE_ATTRIBUTE_ERR = 10;

    public const INVALID_STATE_ERR = 11;

    public const SYNTAX_ERR = 12;

    public const INVALID_MODIFICATION_ERR = 13;

    public const NAMESPACE_ERR = 14;

    public const INVALID_ACCESS_ERR = 15;

    public const VALIDATION_ERR = 16;

    /**
     * Raise DOMException with Zend message + W3C/DOM error code (php-src
     * ext/dom/php_dom.c dom_get_domexception; #22658 / #22694).
     *
     * @throws \DOMException
     * @return never
     */
    public static function raise(string $message, int $code): void
    {
        throw new \DOMException($message, $code);
    }

    /**
     * Wrong Document Error (WRONG_DOCUMENT_ERR = 4).
     *
     * @throws \DOMException
     * @return never
     */
    public static function raiseWrongDocument(): void
    {
        self::raise('Wrong Document Error', self::WRONG_DOCUMENT_ERR);
    }

    /**
     * Not Found Error (NOT_FOUND_ERR = 8) — Zend title-case message.
     *
     * @throws \DOMException
     * @return never
     */
    public static function raiseNotFound(): void
    {
        self::raise('Not Found Error', self::NOT_FOUND_ERR);
    }

    /** @return array<string, int> */
    public static function globalConstants(): array
    {
        return [
            'DOM_INDEX_SIZE_ERR' => self::INDEX_SIZE_ERR,
            'DOMSTRING_SIZE_ERR' => self::STRING_SIZE_ERR,
            'DOM_HIERARCHY_REQUEST_ERR' => self::HIERARCHY_REQUEST_ERR,
            'DOM_WRONG_DOCUMENT_ERR' => self::WRONG_DOCUMENT_ERR,
            'DOM_INVALID_CHARACTER_ERR' => self::INVALID_CHARACTER_ERR,
            'DOM_NO_DATA_ALLOWED_ERR' => self::NO_DATA_ALLOWED_ERR,
            'DOM_NO_MODIFICATION_ALLOWED_ERR' => self::NO_MODIFICATION_ALLOWED_ERR,
            'DOM_NOT_FOUND_ERR' => self::NOT_FOUND_ERR,
            'DOM_NOT_SUPPORTED_ERR' => self::NOT_SUPPORTED_ERR,
            'DOM_INUSE_ATTRIBUTE_ERR' => self::INUSE_ATTRIBUTE_ERR,
            'DOM_INVALID_STATE_ERR' => self::INVALID_STATE_ERR,
            'DOM_SYNTAX_ERR' => self::SYNTAX_ERR,
            'DOM_INVALID_MODIFICATION_ERR' => self::INVALID_MODIFICATION_ERR,
            'DOM_NAMESPACE_ERR' => self::NAMESPACE_ERR,
            'DOM_INVALID_ACCESS_ERR' => self::INVALID_ACCESS_ERR,
            'DOM_VALIDATION_ERR' => self::VALIDATION_ERR,
        ];
    }

    public static function registerGlobals(Context $ctx): void
    {
        foreach (self::globalConstants() as $name => $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ctx->defineConstant($name, $var);
        }
    }

    public static function registerOnClass(ClassEntry $entry): void
    {
        DomClassConstants::registerIntConstants($entry, [
            'INDEX_SIZE_ERR' => self::INDEX_SIZE_ERR,
            'STRING_SIZE_ERR' => self::STRING_SIZE_ERR,
            'HIERARCHY_REQUEST_ERR' => self::HIERARCHY_REQUEST_ERR,
            'WRONG_DOCUMENT_ERR' => self::WRONG_DOCUMENT_ERR,
            'INVALID_CHARACTER_ERR' => self::INVALID_CHARACTER_ERR,
            'NO_DATA_ALLOWED_ERR' => self::NO_DATA_ALLOWED_ERR,
            'NO_MODIFICATION_ALLOWED_ERR' => self::NO_MODIFICATION_ALLOWED_ERR,
            'NOT_FOUND_ERR' => self::NOT_FOUND_ERR,
            'NOT_SUPPORTED_ERR' => self::NOT_SUPPORTED_ERR,
            'INUSE_ATTRIBUTE_ERR' => self::INUSE_ATTRIBUTE_ERR,
            'INVALID_STATE_ERR' => self::INVALID_STATE_ERR,
            'SYNTAX_ERR' => self::SYNTAX_ERR,
            'INVALID_MODIFICATION_ERR' => self::INVALID_MODIFICATION_ERR,
            'NAMESPACE_ERR' => self::NAMESPACE_ERR,
            'INVALID_ACCESS_ERR' => self::INVALID_ACCESS_ERR,
            'VALIDATION_ERR' => self::VALIDATION_ERR,
        ]);
    }
}
