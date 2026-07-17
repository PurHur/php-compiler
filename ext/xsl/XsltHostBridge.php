<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

/**
 * Thin host ext/xsl bridge for v1 XSLTProcessor parity (#3665).
 *
 * Internal VM methods run as host PHP — safe to wrap Zend {@see \XSLTProcessor} until
 * native libxslt FFI lands. No runtime/*.c growth.
 */
final class XsltHostBridge
{
    public static function available(): bool
    {
        return \extension_loaded('xsl') && \class_exists(\XSLTProcessor::class, false);
    }

    public static function createProcessor(): \XSLTProcessor
    {
        if (!self::available()) {
            throw new \LogicException('XSLTProcessor requires host ext/xsl in this compiler build');
        }

        return new \XSLTProcessor();
    }

    public static function importStylesheet(\XSLTProcessor $proc, \DOMDocument $stylesheet): void
    {
        $proc->importStylesheet($stylesheet);
    }

    /** @return string|false */
    public static function transformToXml(\XSLTProcessor $proc, \DOMDocument $doc)
    {
        return $proc->transformToXML($doc);
    }

    public static function transformToDoc(\XSLTProcessor $proc, \DOMDocument $doc): \DOMDocument|false
    {
        $result = $proc->transformToDoc($doc);
        if (false === $result) {
            return false;
        }
        if (!$result instanceof \DOMDocument) {
            return false;
        }

        return $result;
    }

    public static function setParameter(\XSLTProcessor $proc, string $namespace, string $name, string $value): bool
    {
        return $proc->setParameter($namespace, $name, $value);
    }

    public static function getParameter(\XSLTProcessor $proc, string $namespace, string $name): string
    {
        return $proc->getParameter($namespace, $name);
    }

    public static function removeParameter(\XSLTProcessor $proc, string $namespace, string $name): bool
    {
        return $proc->removeParameter($namespace, $name);
    }

    /**
     * @param null|string|list<string> $restrict
     */
    public static function registerPHPFunctions(\XSLTProcessor $proc, null|string|array $restrict = null): void
    {
        if (null === $restrict) {
            $proc->registerPHPFunctions();

            return;
        }
        $proc->registerPHPFunctions($restrict);
    }
}
