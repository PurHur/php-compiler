<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\StringableSupport;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * PhpToken OOP API (php-src ext/tokenizer/tokenizer.c; issues #6077, #6794).
 */
final class VmPhpToken
{
    public const CLASS_LC = 'phptoken';

    public const PROP_ID = 'id';

    public const PROP_TEXT = 'text';

    public const PROP_LINE = 'line';

    public const PROP_POS = 'pos';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $intProto = new Variable(Variable::TYPE_INTEGER);
        $strProto = new Variable(Variable::TYPE_STRING);
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;

        $entry = new ClassEntry('PhpToken');
        $entry->interfaces = [StringableSupport::INTERFACE_LC];
        $entry->properties[] = new ClassProperty(self::PROP_ID, null, $intProto);
        $entry->properties[] = new ClassProperty(self::PROP_TEXT, null, $strProto);
        $entry->properties[] = new ClassProperty(self::PROP_LINE, null, $intProto);
        $entry->properties[] = new ClassProperty(self::PROP_POS, null, $intProto);

        $entry->constructor = new PhpTokenConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['tokenize'] = new PhpTokenTokenize();
        $entry->methodVisibility['tokenize'] = $pubStatic;
        $entry->methods['is'] = new PhpTokenIs();
        $entry->methodVisibility['is'] = $pub;
        $entry->methods['isignorable'] = new PhpTokenIsIgnorable();
        $entry->methodVisibility['isignorable'] = $pub;
        $entry->methods['gettokenname'] = new PhpTokenGetTokenName();
        $entry->methodVisibility['gettokenname'] = $pub;
        $entry->methods['__tostring'] = new PhpTokenToString();
        $entry->methodVisibility['__tostring'] = $pub;
        $entry->methodNames['isignorable'] = 'isIgnorable';
        $entry->methodNames['gettokenname'] = 'getTokenName';
        $entry->methodNames['__tostring'] = '__toString';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * @return list<ObjectEntry>
     */
    public static function tokenize(Context $ctx, string $code, int $flags = 0): array
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('PhpToken is not registered in this compiler build');
        }

        $out = [];
        foreach (self::tokenizeParts($code, $flags) as $part) {
            $out[] = self::createObject(
                $class,
                $part['id'],
                $part['text'],
                $part['line'],
                $part['pos']
            );
        }

        return $out;
    }

    /**
     * Token stream without ClassEntry — JIT/AOT materialization (#27263).
     *
     * @return list<array{id: int, text: string, line: int, pos: int}>
     */
    public static function tokenizeParts(string $code, int $flags = 0): array
    {
        return self::normalizeTokensWithPositions($code, LanguageScanner::tokenize($code, $flags));
    }

    public static function createObject(
        ClassEntry $class,
        int $id,
        string $text,
        int $line,
        int $pos
    ): ObjectEntry {
        $entry = new ObjectEntry($class);
        $entry->getProperty(self::PROP_ID)->int($id);
        $entry->getProperty(self::PROP_TEXT)->string($text);
        $entry->getProperty(self::PROP_LINE)->int($line);
        $entry->getProperty(self::PROP_POS)->int($pos);
        $entry->constructed = true;

        return $entry;
    }

    public static function requirePhpToken(Variable $var, string $fn, int $argNum, string $param): ObjectEntry
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(
                sprintf('%s(): Argument #%d ($%s) must be of type PhpToken, %s given', $fn, $argNum, $param, EnumCaseSupport::typeNameForVariable($resolved))
            );
        }
        $entry = $resolved->toObject();
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(
                sprintf('%s(): Argument #%d ($%s) must be of type PhpToken, %s given', $fn, $argNum, $param, $entry->class->name)
            );
        }

        return $entry;
    }

    public static function readIntProperty(ObjectEntry $entry, string $name): int
    {
        return $entry->getProperty($name)->toInt();
    }

    public static function readStringProperty(ObjectEntry $entry, string $name): string
    {
        return $entry->getProperty($name)->toString();
    }

    public static function getTokenName(ObjectEntry $entry): ?string
    {
        $id = self::readIntProperty($entry, self::PROP_ID);
        $text = self::readStringProperty($entry, self::PROP_TEXT);
        $name = TokenConstants::nameForId($id);
        if (null !== $name) {
            return $name;
        }

        if (1 === \strlen($text)) {
            return $text;
        }

        return null;
    }

    public static function isIgnorable(ObjectEntry $entry): bool
    {
        $id = self::readIntProperty($entry, self::PROP_ID);

        return \in_array($id, self::ignorableIds(), true);
    }

    /**
     * @param int|string|list<int|string> $kind
     */
    public static function matchesKind(ObjectEntry $entry, $kind): bool
    {
        if (\is_array($kind)) {
            foreach ($kind as $item) {
                if (self::matchesKind($entry, $item)) {
                    return true;
                }
            }

            return false;
        }

        $id = self::readIntProperty($entry, self::PROP_ID);
        $text = self::readStringProperty($entry, self::PROP_TEXT);

        if (\is_int($kind)) {
            return $id === $kind;
        }

        if (\is_string($kind)) {
            if (\is_numeric($kind) && (string) (int) $kind === $kind) {
                return $id === (int) $kind;
            }
            $name = TokenConstants::nameForId($id);
            if (null !== $name && 0 === strcasecmp($name, $kind)) {
                return true;
            }

            return 0 === strcasecmp($text, $kind);
        }

        throw new \TypeError('PhpToken::is(): Argument #1 ($kind) must be of type array|string|int');
    }

    /**
     * @param list<int|string|array{0: int, 1: string, 2: int}> $raw
     *
     * @return list<array{id: int, text: string, line: int, pos: int}>
     */
    private static function normalizeTokensWithPositions(string $source, array $raw): array
    {
        $pos = 0;
        $len = \strlen($source);
        $out = [];
        foreach ($raw as $token) {
            if (\is_string($token)) {
                $text = $token;
                $id = \ord($text);
                $tokenPos = self::findTokenPosition($source, $text, $pos, $len);
                $line = self::lineAtOffset($source, $tokenPos);
                $pos = $tokenPos + \strlen($text);
                $out[] = ['id' => $id, 'text' => $text, 'line' => $line, 'pos' => $tokenPos];
                continue;
            }

            [$id, $text, $line] = $token;
            $tokenPos = self::findTokenPosition($source, $text, $pos, $len);
            $pos = $tokenPos + \strlen($text);
            $out[] = ['id' => $id, 'text' => $text, 'line' => $line, 'pos' => $tokenPos];
        }

        return $out;
    }

    private static function findTokenPosition(string $source, string $text, int $pos, int $len): int
    {
        if ('' === $text) {
            return $pos;
        }
        if ($pos < $len) {
            $found = \strpos($source, $text, $pos);
            if (false !== $found) {
                return $found;
            }
        }

        return $pos;
    }

    private static function lineAtOffset(string $source, int $offset): int
    {
        $line = 1;
        $max = \min($offset, \strlen($source));
        for ($i = 0; $i < $max; ++$i) {
            if ("\n" === $source[$i]) {
                ++$line;
            }
        }

        return $line;
    }

    /** @return list<int> */
    private static function ignorableIds(): array
    {
        static $ids = null;
        if (null !== $ids) {
            return $ids;
        }

        $names = TokenConstantsData::nameToId();
        $ids = [];
        foreach (['T_OPEN_TAG', 'T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT'] as $name) {
            if (isset($names[$name])) {
                $ids[] = $names[$name];
            }
        }

        return $ids;
    }
}
