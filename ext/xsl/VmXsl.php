<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\ext\standard\VmCallable;

/**
 * XSLTProcessor — host ext/xsl bridge v1 (php-src ext/xsl/xsltprocessor.c; #3665).
 */
final class VmXsl
{
    public const CLASS_LC = 'xsltprocessor';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        if (!XslExtensionPolicy::advertisesExtension()) {
            return;
        }

        $pub = \PHPCfg\Func::FLAG_PUBLIC;

        $entry = new ClassEntry('XSLTProcessor');
        $construct = new XsltProcessorConstruct();
        $entry->constructor = $construct;
        $entry->methods['__construct'] = $construct;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['importstylesheet'] = new XsltProcessorImportStylesheet();
        $entry->methodVisibility['importstylesheet'] = $pub;
        $entry->methodNames['importstylesheet'] = 'importStylesheet';
        $entry->methods['transformtoxml'] = new XsltProcessorTransformToXml();
        $entry->methodVisibility['transformtoxml'] = $pub;
        $entry->methodNames['transformtoxml'] = 'transformToXML';
        $entry->methods['transformtodoc'] = new XsltProcessorTransformToDoc();
        $entry->methodVisibility['transformtodoc'] = $pub;
        $entry->methodNames['transformtodoc'] = 'transformToDoc';
        $entry->methods['transformtouri'] = new XsltProcessorTransformToUri();
        $entry->methodVisibility['transformtouri'] = $pub;
        $entry->methodNames['transformtouri'] = 'transformToUri';
        $entry->methods['setparameter'] = new XsltProcessorSetParameter();
        $entry->methodVisibility['setparameter'] = $pub;
        $entry->methodNames['setparameter'] = 'setParameter';
        $entry->methods['getparameter'] = new XsltProcessorGetParameter();
        $entry->methodVisibility['getparameter'] = $pub;
        $entry->methodNames['getparameter'] = 'getParameter';
        $entry->methods['removeparameter'] = new XsltProcessorRemoveParameter();
        $entry->methodVisibility['removeparameter'] = $pub;
        $entry->methodNames['removeparameter'] = 'removeParameter';
        $entry->methods['registerphpfunctions'] = new XsltProcessorRegisterPhpFunctions();
        $entry->methodVisibility['registerphpfunctions'] = $pub;
        $entry->methodNames['registerphpfunctions'] = 'registerPHPFunctions';
        if (CompilerVersion::supportsXsltRegisterPHPFunctionNS()) {
            $entry->methods['registerphpfunctionns'] = new XsltProcessorRegisterPhpFunctionNS();
            $entry->methodVisibility['registerphpfunctionns'] = $pub;
            $entry->methodNames['registerphpfunctionns'] = 'registerPHPFunctionNS';
        }
        $entry->methods['hasexsltsupport'] = new XsltProcessorHasExsltSupport();
        $entry->methodVisibility['hasexsltsupport'] = $pub;
        $entry->methodNames['hasexsltsupport'] = 'hasExsltSupport';
        $entry->methods['setsecurityprefs'] = new XsltProcessorSetSecurityPrefs();
        $entry->methodVisibility['setsecurityprefs'] = $pub;
        $entry->methodNames['setsecurityprefs'] = 'setSecurityPrefs';
        $entry->methods['getsecurityprefs'] = new XsltProcessorGetSecurityPrefs();
        $entry->methodVisibility['getsecurityprefs'] = $pub;
        $entry->methodNames['getsecurityprefs'] = 'getSecurityPrefs';
        $entry->methods['setprofiling'] = new XsltProcessorSetProfiling();
        $entry->methodVisibility['setprofiling'] = $pub;
        $entry->methodNames['setprofiling'] = 'setProfiling';

        $ctx->classes[self::CLASS_LC] = $entry;
        $ctx->classes[self::CLASS_LC]->isInternal = true;
    }

    /**
     * Withhold XSLTProcessor from class_exists() when host ext/xsl is absent (#3665).
     */
    public static function isHiddenClassEntry(ClassEntry $entry): bool
    {
        if (XslExtensionPolicy::advertisesExtension()) {
            return false;
        }

        return self::CLASS_LC === strtolower(ltrim($entry->name, '\\'));
    }

    public static function requireProcessor(ObjectEntry $entry, string $label): ObjectEntry
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be XSLTProcessor, %s given', $label, $entry->class->name));
        }
        if (!XsltRegistry::has($entry)) {
            throw new \LogicException($label.' called on uninitialized XSLTProcessor');
        }

        return $entry;
    }

    public static function construct(ObjectEntry $entry): void
    {
        XsltRegistry::attach($entry, XsltHostBridge::createProcessor());
    }

    /**
     * XSLTProcessor::importStylesheet() — php-src xsltprocessor.c (#22367).
     *
     * @return bool Success of stylesheet import (Zend returns true/false, never null).
     */
    public static function importStylesheet(ObjectEntry $entry, ObjectEntry $stylesheet): bool
    {
        $hostStylesheet = VmXslDomBridge::vmDocumentToHost($stylesheet);

        return XsltHostBridge::importStylesheet(XsltRegistry::processor($entry), $hostStylesheet);
    }

    /** @return string|false */
    public static function transformToXml(ObjectEntry $entry, ObjectEntry $document)
    {
        self::syncPhpFunctionsBeforeTransform($entry);
        $hostDoc = VmXslDomBridge::vmDocumentToHost($document);

        return XsltHostBridge::transformToXml(XsltRegistry::processor($entry), $hostDoc);
    }

    /** @return ObjectEntry|false */
    public static function transformToDoc(Context $ctx, ObjectEntry $entry, ObjectEntry $document)
    {
        self::syncPhpFunctionsBeforeTransform($entry);
        $hostDoc = VmXslDomBridge::vmDocumentToHost($document);
        $hostResult = XsltHostBridge::transformToDoc(XsltRegistry::processor($entry), $hostDoc);
        if (false === $hostResult) {
            return false;
        }

        return VmXslDomBridge::hostDocumentToVm($ctx, $hostResult);
    }

    /** @return int|false */
    public static function transformToUri(ObjectEntry $entry, ObjectEntry $document, string $uri)
    {
        self::syncPhpFunctionsBeforeTransform($entry);
        $hostDoc = VmXslDomBridge::vmDocumentToHost($document);

        return XsltHostBridge::transformToUri(XsltRegistry::processor($entry), $hostDoc, $uri);
    }

    public static function setParameter(ObjectEntry $entry, string $namespace, string $name, string $value): bool
    {
        return XsltHostBridge::setParameter(XsltRegistry::processor($entry), $namespace, $name, $value);
    }

    public static function getParameter(ObjectEntry $entry, string $namespace, string $name): string
    {
        return XsltHostBridge::getParameter(XsltRegistry::processor($entry), $namespace, $name);
    }

    public static function removeParameter(ObjectEntry $entry, string $namespace, string $name): bool
    {
        return XsltHostBridge::removeParameter(XsltRegistry::processor($entry), $namespace, $name);
    }

    public static function registerPHPFunctions(Context $ctx, ObjectEntry $entry, ?Variable $restrict = null): void
    {
        $hostRestrict = self::hostRegisterPhpFunctionsRestrict($restrict);
        // Materialize host-named trampolines for VM userland before host register (#22632).
        XsltPhpFunctionBridge::sync($ctx, $hostRestrict);
        XsltRegistry::storePhpFunctions($entry, $ctx, $hostRestrict);
        XsltHostBridge::registerPHPFunctions(
            XsltRegistry::processor($entry),
            $hostRestrict
        );
    }

    /**
     * Refresh php:function trampolines immediately before host transform (#22632).
     *
     * Covers unrestricted mode when user functions are declared after registerPHPFunctions().
     */
    public static function syncPhpFunctionsBeforeTransform(ObjectEntry $entry): void
    {
        $state = XsltRegistry::phpFunctions($entry);
        if (null === $state) {
            return;
        }
        XsltPhpFunctionBridge::sync($state['ctx'], $state['restrict']);
    }

    /**
     * XSLTProcessor::registerPHPFunctionNS() — php-src xsltprocessor.c / xpath_callbacks.c (#22243).
     */
    public static function registerPHPFunctionNS(
        Context $ctx,
        ObjectEntry $entry,
        string $namespaceUri,
        string $name,
        Variable $callable
    ): void {
        self::requireProcessor($entry, 'XSLTProcessor::registerPHPFunctionNS()');
        if (str_contains($namespaceUri, "\0")) {
            throw new \ValueError(
                'XSLTProcessor::registerPHPFunctionNS(): Argument #1 ($namespaceURI) must not contain any null bytes'
            );
        }
        if (str_contains($name, "\0")) {
            throw new \ValueError(
                'XSLTProcessor::registerPHPFunctionNS(): Argument #2 ($name) must not contain any null bytes'
            );
        }
        $callable = $callable->resolveIndirect();
        if (!VmCallable::isCallable($ctx, $callable)) {
            throw new \TypeError(sprintf(
                'XSLTProcessor::registerPHPFunctionNS(): Argument #3 ($callable) must be of type callable, %s given',
                VmDom::typeLabel($callable)
            ));
        }
        // After Z_PARAM_* — reserved URI then NCName (php-src xsltprocessor.c / xpath_callbacks.c).
        if ('http://php.net/xsl' === $namespaceUri) {
            throw new \ValueError(
                'XSLTProcessor::registerPHPFunctionNS(): Argument #1 ($namespaceURI) must not be "http://php.net/xsl" because it is reserved by PHP'
            );
        }
        if (!self::isValidXsltNcName($name)) {
            throw new \ValueError(
                'XSLTProcessor::registerPHPFunctionNS(): Argument #2 ($name) must be a valid callback name'
            );
        }
        XsltRegistry::storePhpFunctionNS($entry, $namespaceUri, $name, $callable);
        $hostCallable = self::hostCallableFromVariable($callable);
        if (null !== $hostCallable) {
            XsltHostBridge::registerPHPFunctionNS(
                XsltRegistry::processor($entry),
                $namespaceUri,
                $name,
                $hostCallable
            );
        }
    }

    public static function hasExsltSupport(ObjectEntry $entry): bool
    {
        return XsltHostBridge::hasExsltSupport(XsltRegistry::processor($entry));
    }

    public static function setSecurityPrefs(ObjectEntry $entry, int $securityPrefs): int
    {
        return XsltHostBridge::setSecurityPrefs(XsltRegistry::processor($entry), $securityPrefs);
    }

    public static function getSecurityPrefs(ObjectEntry $entry): int
    {
        return XsltHostBridge::getSecurityPrefs(XsltRegistry::processor($entry));
    }

    /**
     * XSLTProcessor::setProfiling() — php-src xsltprocessor.c (#22272).
     *
     * @return bool Always true on success (php-src RETURN_TRUE).
     */
    public static function setProfiling(ObjectEntry $entry, ?string $filename): bool
    {
        self::requireProcessor($entry, 'XSLTProcessor::setProfiling()');

        return XsltHostBridge::setProfiling(XsltRegistry::processor($entry), $filename);
    }

    /**
     * @return null|string|list<string>
     */
    private static function hostRegisterPhpFunctionsRestrict(?Variable $restrict): null|string|array
    {
        if (null === $restrict || Variable::TYPE_NULL === $restrict->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $restrict->type) {
            $name = $restrict->toString();
            self::assertValidPhpFunctionName($name, false);

            return $name;
        }
        if (Variable::TYPE_ARRAY === $restrict->type || Variable::TYPE_HASHTABLE === $restrict->type) {
            $names = [];
            foreach ($restrict->toArray()->iterateKeyed(false) as $pair) {
                [, $value] = $pair;
                $value = $value->resolveIndirect();
                if (Variable::TYPE_STRING !== $value->type) {
                    throw new \TypeError(
                        'XSLTProcessor::registerPHPFunctions(): Argument #1 ($restrict) must be of type array|string|null, array given with non-string values'
                    );
                }
                $name = $value->toString();
                self::assertValidPhpFunctionName($name, true);
                $names[] = $name;
            }

            return $names;
        }
        throw new \TypeError(sprintf(
            'XSLTProcessor::registerPHPFunctions(): Argument #1 ($restrict) must be of type array|string|null, %s given',
            VmDom::typeLabel($restrict)
        ));
    }

    private static function assertValidPhpFunctionName(string $name, bool $fromArray): void
    {
        if ('' === $name || !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $name)) {
            throw new \TypeError(
                $fromArray
                    ? 'XSLTProcessor::registerPHPFunctions(): Argument #1 ($restrict) must be an array containing valid callback names'
                    : 'XSLTProcessor::registerPHPFunctions(): Argument #1 ($restrict) must be a valid callback name'
            );
        }
    }

    /** xmlValidateNCName(name, 0) subset used by php-src xpath_callbacks.c (#22243). */
    private static function isValidXsltNcName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }

        // NCName: no colon; Letter|'_' start; then NameChar without ':'.
        return 1 === preg_match('/^[A-Za-z_][\w.-]*$/u', $name);
    }

    /**
     * Materialize a host-callable for XsltHostBridge when possible (string names).
     *
     * @return callable|null
     */
    private static function hostCallableFromVariable(Variable $callable): ?callable
    {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_STRING !== $callable->type) {
            return null;
        }
        $name = $callable->toString();
        if (!\is_callable($name)) {
            return null;
        }

        return $name;
    }
}
