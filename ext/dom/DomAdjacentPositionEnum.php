<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\Variable;

/**
 * Dom\AdjacentPosition string-backed enum (php-src ext/dom/php_dom.stub.php; #20782).
 *
 * Cases mirror insertAdjacent* position strings:
 * BeforeBegin / AfterBegin / BeforeEnd / AfterEnd.
 */
final class DomAdjacentPositionEnum
{
    public const CLASS_NAME = 'Dom\\AdjacentPosition';

    public const CLASS_LC = 'dom\\adjacentposition';

    /** @var array<string, string> */
    private const CASES = [
        'BeforeBegin' => 'beforebegin',
        'AfterBegin' => 'afterbegin',
        'BeforeEnd' => 'beforeend',
        'AfterEnd' => 'afterend',
    ];

    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry(self::CLASS_NAME);
        $entry->isEnum = true;
        $entry->isInternal = true;
        $entry->backedType = 'string';

        foreach (self::CASES as $name => $value) {
            self::registerStringBackedCase($entry, $name, $value);
        }

        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);

        $ctx->classes[self::CLASS_LC] = $entry;
        $ctx->enums[self::CLASS_LC] = true;
    }

    public static function isAdjacentPositionEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), self::CLASS_NAME);
    }

    /**
     * Resolve Dom\AdjacentPosition case → backing position string, or null if not that enum.
     */
    public static function tryPositionString(Variable $var): ?string
    {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return null;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isAdjacentPositionEnum($enumClass->name)) {
            return null;
        }
        $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
        if (null === $entry) {
            throw new \LogicException('Dom\\AdjacentPosition case missing backing value');
        }
        $backing = $entry->backingValue->resolveIndirect();
        if (Variable::TYPE_STRING !== $backing->type) {
            throw new \LogicException('Dom\\AdjacentPosition expects string backing');
        }

        return $backing->toString();
    }

    private static function registerStringBackedCase(ClassEntry $enum, string $name, string $value): void
    {
        $lc = \PHPCompiler\ClassConstName::key($name);
        $backing = new Variable();
        $backing->string($value);
        $case = EnumCaseSupport::createCase($enum, $name, $backing);
        $enum->constants[$lc] = $case;
        $enum->enumCaseCanonicalNames[$lc] = $name;
        $enum->enumCases[] = [
            'name' => $name,
            'value' => $backing,
        ];
    }
}
