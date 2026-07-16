<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * IntlBreakIterator / IntlRuleBasedBreakIterator / IntlPartsIterator — ICU ubrk_* via FFI + ASCII fallback (#6188).
 *
 * php-src: ext/intl/breakiterator/breakiterator_class.c, breakiterator_methods.c, breakiterator_iterators.c
 * ICU: unicode/ubrk.h — versioned ubrk_open_N / ubrk_first_N / ubrk_next_N / ubrk_close_N
 */
final class VmBreakIterator
{
    public const CLASS_LC = 'intlbreakiterator';
    public const RULE_BASED_LC = 'intlrulebasedbreakiterator';
    public const PARTS_LC = 'intlpartsiterator';
    public const DONE = -1;
    private const UBRK_CHARACTER = 0;
    private const UBRK_WORD = 1;
    private const UBRK_LINE = 2;
    private const UBRK_SENTENCE = 3;
    private const PROP_HANDLE = '__breakiterator_id';

    /** @var array<int, array<string, mixed>> */
    private static array $state = [];
    private static int $nextId = 1;
    private static ?\FFI $ffi = null;
    private static string $symSuffix = '';
    private static bool $ffiUnavailable = false;

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'DONE' => self::DONE,
            'WORD_NONE' => 0,
            'WORD_NONE_LIMIT' => 100,
            'WORD_NUMBER' => 100,
            'WORD_NUMBER_LIMIT' => 200,
            'WORD_LETTER' => 200,
            'WORD_LETTER_LIMIT' => 300,
            'WORD_KANA' => 300,
            'WORD_KANA_LIMIT' => 400,
            'WORD_IDEO' => 400,
            'WORD_IDEO_LIMIT' => 500,
            'LINE_SOFT' => 0,
            'LINE_SOFT_LIMIT' => 100,
            'LINE_HARD' => 100,
            'LINE_HARD_LIMIT' => 200,
            'SENTENCE_TERM' => 0,
            'SENTENCE_TERM_LIMIT' => 100,
            'SENTENCE_SEP' => 100,
            'SENTENCE_SEP_LIMIT' => 200,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $strProto = new Variable(Variable::TYPE_STRING);
        $strProto->string('');

        $entry = new ClassEntry('IntlBreakIterator');
        $entry->isInternal = true;
        $entry->isAbstract = true;
        self::installConstants($entry);
        $entry->properties[] = new ClassProperty(self::PROP_HANDLE, null, $strProto);
        self::installFactories($entry, $pubStatic);
        self::installInstanceMethods($entry, $pub);
        $ctx->classes[self::CLASS_LC] = $entry;

        $rb = new ClassEntry('IntlRuleBasedBreakIterator');
        $rb->isInternal = true;
        $rb->parentLc = self::CLASS_LC;
        self::installConstants($rb);
        $rb->properties[] = new ClassProperty(self::PROP_HANDLE, null, $strProto);
        self::installFactories($rb, $pubStatic);
        self::installInstanceMethods($rb, $pub);
        $ctx->classes[self::RULE_BASED_LC] = $rb;

        $parts = new ClassEntry('IntlPartsIterator');
        $parts->isInternal = true;
        $parts->properties[] = new ClassProperty(self::PROP_HANDLE, null, $strProto);
        foreach ([
            'current' => [new PartsIteratorCurrent(), 'current'],
            'key' => [new PartsIteratorKey(), 'key'],
            'next' => [new PartsIteratorNext(), 'next'],
            'rewind' => [new PartsIteratorRewind(), 'rewind'],
            'valid' => [new PartsIteratorValid(), 'valid'],
        ] as $lc => [$handler, $name]) {
            $parts->methods[$lc] = $handler;
            $parts->methodVisibility[$lc] = $pub;
            $parts->methodNames[$lc] = $name;
        }
        $ctx->classes[self::PARTS_LC] = $parts;
    }

    private static function installConstants(ClassEntry $entry): void
    {
        foreach (self::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
    }

    private static function installFactories(ClassEntry $entry, int $vis): void
    {
        $factories = [
            'createwordinstance' => [new BreakIteratorCreateWordInstance(), 'createWordInstance'],
            'createcharacterinstance' => [new BreakIteratorCreateCharacterInstance(), 'createCharacterInstance'],
            'createlineinstance' => [new BreakIteratorCreateLineInstance(), 'createLineInstance'],
            'createsentenceinstance' => [new BreakIteratorCreateSentenceInstance(), 'createSentenceInstance'],
            'createtitleinstance' => [new BreakIteratorCreateTitleInstance(), 'createTitleInstance'],
        ];
        foreach ($factories as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }
    }

    private static function installInstanceMethods(ClassEntry $entry, int $vis): void
    {
        $methods = [
            'settext' => [new BreakIteratorSetText(), 'setText'],
            'gettext' => [new BreakIteratorGetText(), 'getText'],
            'first' => [new BreakIteratorFirst(), 'first'],
            'last' => [new BreakIteratorLast(), 'last'],
            'next' => [new BreakIteratorNext(), 'next'],
            'previous' => [new BreakIteratorPrevious(), 'previous'],
            'current' => [new BreakIteratorCurrent(), 'current'],
            'getpartsiterator' => [new BreakIteratorGetPartsIterator(), 'getPartsIterator'],
        ];
        foreach ($methods as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }
    }

    public static function createInstance(Context $ctx, int $type, ?string $locale): ?ObjectEntry
    {
        $class = $ctx->classes[self::RULE_BASED_LC] ?? null;
        if (null === $class) {
            return null;
        }
        $obj = new ObjectEntry($class);
        $id = self::$nextId++;
        $obj->getProperty(self::PROP_HANDLE)->string((string) $id);
        self::$state[$id] = [
            'type' => $type,
            'locale' => $locale ?? 'en_US',
            'text' => '',
            'pos' => 0,
            'boundaries' => [0],
            'parts' => null,
            'index' => 0,
        ];
        $obj->constructed = true;
        IntlError::clear();

        return $obj;
    }

    public static function requireBreakIterator(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('IntlBreakIterator method called without object');
        }
        $obj = $receiver->toObject();
        $lc = strtolower($obj->class->name);
        if (self::CLASS_LC !== $lc && self::RULE_BASED_LC !== $lc) {
            throw new \LogicException('Expected IntlBreakIterator instance');
        }

        return $obj;
    }

    public static function stateId(ObjectEntry $obj): int
    {
        return (int) $obj->getProperty(self::PROP_HANDLE)->toString();
    }

    /** @return array<string, mixed> */
    public static function &stateRef(ObjectEntry $obj): array
    {
        $id = self::stateId($obj);
        if (!isset(self::$state[$id])) {
            throw new \LogicException('IntlBreakIterator state missing');
        }

        return self::$state[$id];
    }

    public static function setText(ObjectEntry $obj, string $text): bool
    {
        $st = &self::stateRef($obj);
        $st['text'] = $text;
        $st['boundaries'] = self::computeBoundaries((int) $st['type'], (string) $st['locale'], $text);
        $st['pos'] = $st['boundaries'][0] ?? 0;
        IntlError::clear();

        return true;
    }

    public static function getText(ObjectEntry $obj): ?string
    {
        $st = self::stateRef($obj);
        if ('' === $st['text']) {
            return null;
        }

        return $st['text'];
    }

    public static function first(ObjectEntry $obj): int
    {
        $st = &self::stateRef($obj);
        $st['pos'] = $st['boundaries'][0] ?? 0;

        return (int) $st['pos'];
    }

    public static function last(ObjectEntry $obj): int
    {
        $st = &self::stateRef($obj);
        $bounds = $st['boundaries'];
        $st['pos'] = $bounds[\count($bounds) - 1] ?? 0;

        return (int) $st['pos'];
    }

    public static function currentPos(ObjectEntry $obj): int
    {
        return (int) self::stateRef($obj)['pos'];
    }

    public static function nextPos(ObjectEntry $obj, ?int $offset = null): int
    {
        $st = &self::stateRef($obj);
        $bounds = $st['boundaries'];
        if (null !== $offset) {
            foreach ($bounds as $b) {
                if ($b > $offset) {
                    $st['pos'] = $b;

                    return (int) $b;
                }
            }
            $st['pos'] = self::DONE;

            return self::DONE;
        }
        $cur = (int) $st['pos'];
        foreach ($bounds as $b) {
            if ($b > $cur) {
                $st['pos'] = $b;

                return (int) $b;
            }
        }
        $st['pos'] = self::DONE;

        return self::DONE;
    }

    public static function previousPos(ObjectEntry $obj): int
    {
        $st = &self::stateRef($obj);
        $bounds = $st['boundaries'];
        $cur = (int) $st['pos'];
        $prev = self::DONE;
        foreach ($bounds as $b) {
            if ($b >= $cur) {
                break;
            }
            $prev = (int) $b;
        }
        $st['pos'] = $prev;

        return $prev;
    }

    /** @return list<string> */
    public static function parts(ObjectEntry $obj): array
    {
        $st = self::stateRef($obj);
        $text = (string) $st['text'];
        $bounds = $st['boundaries'];
        $parts = [];
        for ($i = 0, $n = \count($bounds) - 1; $i < $n; ++$i) {
            $parts[] = substr($text, (int) $bounds[$i], (int) $bounds[$i + 1] - (int) $bounds[$i]);
        }

        return $parts;
    }

    public static function createPartsIterator(Context $ctx, ObjectEntry $bi): ObjectEntry
    {
        $class = $ctx->classes[self::PARTS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('IntlPartsIterator is not registered');
        }
        $obj = new ObjectEntry($class);
        $id = self::$nextId++;
        $obj->getProperty(self::PROP_HANDLE)->string((string) $id);
        self::$state[$id] = [
            'type' => -1,
            'locale' => '',
            'text' => '',
            'pos' => 0,
            'boundaries' => [],
            'parts' => self::parts($bi),
            'index' => 0,
        ];
        $obj->constructed = true;

        return $obj;
    }

    public static function requirePartsIterator(Frame $frame, Variable $receiver): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('IntlPartsIterator method called without object');
        }
        $obj = $receiver->toObject();
        if (self::PARTS_LC !== strtolower($obj->class->name)) {
            throw new \LogicException('Expected IntlPartsIterator instance');
        }

        return $obj;
    }

    /** @return list<int> */
    private static function computeBoundaries(int $type, string $locale, string $text): array
    {
        $icu = self::icuBoundaries($type, $locale, $text);
        if (null !== $icu) {
            return $icu;
        }

        return self::fallbackBoundaries($type, $text);
    }

    /** @return list<int>|null */
    private static function icuBoundaries(int $type, string $locale, string $text): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        try {
            $codes = self::utf8ToUChars($text);
            $n = \count($codes);
            $buf = $ffi->new('UChar[' . ($n + 1) . ']');
            for ($i = 0; $i < $n; ++$i) {
                $buf[$i] = $codes[$i];
            }
            $buf[$n] = 0;
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $open = 'ubrk_open' . self::$symSuffix;
            $close = 'ubrk_close' . self::$symSuffix;
            $first = 'ubrk_first' . self::$symSuffix;
            $next = 'ubrk_next' . self::$symSuffix;
            $bi = $ffi->$open($type, $locale, $buf, $n, \FFI::addr($status));
            if (null === $bi || (int) $status->cdata > 0) {
                return null;
            }
            $bounds = [];
            $p = (int) $ffi->$first($bi);
            $bounds[] = $p;
            while (true) {
                $p = (int) $ffi->$next($bi);
                if (self::DONE === $p) {
                    break;
                }
                $bounds[] = $p;
            }
            $ffi->$close($bi);

            return $bounds;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<int> */
    private static function fallbackBoundaries(int $type, string $text): array
    {
        $len = \strlen($text);
        if (0 === $len) {
            return [0];
        }
        if (self::UBRK_CHARACTER === $type) {
            $bounds = [0];
            $i = 0;
            while ($i < $len) {
                $c = \ord($text[$i]);
                if ($c < 0x80) {
                    ++$i;
                } elseif (($c & 0xE0) === 0xC0) {
                    $i += 2;
                } elseif (($c & 0xF0) === 0xE0) {
                    $i += 3;
                } elseif (($c & 0xF8) === 0xF0) {
                    $i += 4;
                } else {
                    ++$i;
                }
                $bounds[] = min($i, $len);
            }

            return $bounds;
        }
        if (self::UBRK_WORD === $type) {
            $bounds = [0];
            $prevWord = null;
            for ($i = 0; $i < $len; ++$i) {
                $isWord = (bool) preg_match('/[A-Za-z0-9_]/', $text[$i]);
                if (null !== $prevWord && $isWord !== $prevWord) {
                    $bounds[] = $i;
                }
                $prevWord = $isWord;
            }
            $bounds[] = $len;

            return array_values(array_unique($bounds));
        }
        if (self::UBRK_LINE === $type) {
            $bounds = [0];
            for ($i = 0; $i < $len; ++$i) {
                if ("\n" === $text[$i] || "\r" === $text[$i]) {
                    $bounds[] = $i + 1;
                }
            }
            $bounds[] = $len;

            return array_values(array_unique($bounds));
        }
        $bounds = [0];
        for ($i = 0; $i < $len; ++$i) {
            if (\in_array($text[$i], ['.', '!', '?'], true)) {
                $j = $i + 1;
                while ($j < $len && ' ' === $text[$j]) {
                    ++$j;
                }
                $bounds[] = $j;
            }
        }
        $bounds[] = $len;

        return array_values(array_unique($bounds));
    }

    /** @return list<int> */
    private static function utf8ToUChars(string $utf8): array
    {
        $codes = [];
        $len = \strlen($utf8);
        $i = 0;
        while ($i < $len) {
            $c = \ord($utf8[$i]);
            if ($c < 0x80) {
                $cp = $c;
                ++$i;
            } elseif (($c & 0xE0) === 0xC0 && $i + 1 < $len) {
                $cp = (($c & 0x1F) << 6) | (\ord($utf8[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($c & 0xF0) === 0xE0 && $i + 2 < $len) {
                $cp = (($c & 0x0F) << 12) | ((\ord($utf8[$i + 1]) & 0x3F) << 6) | (\ord($utf8[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($c & 0xF8) === 0xF0 && $i + 3 < $len) {
                $cp = (($c & 0x07) << 18) | ((\ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((\ord($utf8[$i + 2]) & 0x3F) << 6) | (\ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $cp = 0xFFFD;
                ++$i;
            }
            if ($cp > 0xFFFF) {
                $cp -= 0x10000;
                $codes[] = 0xD800 | ($cp >> 10);
                $codes[] = 0xDC00 | ($cp & 0x3FF);
            } else {
                $codes[] = $cp;
            }
        }

        return $codes;
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            self::$ffiUnavailable = true;

            return null;
        }
        foreach ([
            ['libicuuc.so.74', '_74'],
            ['libicuuc.so.70', '_70'],
            ['libicuuc.so.72', '_72'],
            ['libicuuc.so.71', '_71'],
            ['libicuuc.so', '_70'],
            ['libicuuc.dylib', ''],
        ] as [$lib, $suffix]) {
            try {
                self::$ffi = \FFI::cdef(self::cdefForSuffix($suffix), $lib);
                self::$symSuffix = $suffix;

                return self::$ffi;
            } catch (\Throwable) {
                self::$ffi = null;
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }

    private static function cdefForSuffix(string $suffix): string
    {
        return <<<C
typedef int32_t UErrorCode;
typedef uint16_t UChar;
typedef struct UBreakIterator UBreakIterator;
UBreakIterator *ubrk_open{$suffix}(int32_t type, const char *locale, const UChar *text, int32_t textLength, UErrorCode *status);
void ubrk_close{$suffix}(UBreakIterator *bi);
int32_t ubrk_first{$suffix}(UBreakIterator *bi);
int32_t ubrk_next{$suffix}(UBreakIterator *bi);
C;
    }

    public static function typeWord(): int { return self::UBRK_WORD; }
    public static function typeCharacter(): int { return self::UBRK_CHARACTER; }
    public static function typeLine(): int { return self::UBRK_LINE; }
    public static function typeSentence(): int { return self::UBRK_SENTENCE; }

    public static function coerceLocaleArg(?Variable $arg): ?string
    {
        if (null === $arg) {
            return null;
        }
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return null;
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \TypeError('IntlBreakIterator factory(): Argument #1 ($locale) must be of type ?string');
        }

        return $arg->toString();
    }
}

abstract class BreakIteratorFactoryMethod extends VmClassMethod
{
    abstract protected function breakType(): int;

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'IntlBreakIterator::%s() expects at most 1 argument, %d given',
                $this->getName(),
                $argc
            ));
        }
        $locale = 1 === $argc ? VmBreakIterator::coerceLocaleArg($frame->calledArgs[0]) : null;
        $ctx = $frame->vmContext ?? null;
        if (null === $ctx) {
            throw new \LogicException('IntlBreakIterator factory requires VM context');
        }
        $obj = VmBreakIterator::createInstance($ctx, $this->breakType(), $locale);
        if (null !== $frame->returnVar) {
            null === $obj ? $frame->returnVar->null() : $frame->returnVar->object($obj);
        }
    }
}

final class BreakIteratorCreateWordInstance extends BreakIteratorFactoryMethod
{
    public function __construct() { parent::__construct('createWordInstance'); }
    protected function breakType(): int { return VmBreakIterator::typeWord(); }
}
final class BreakIteratorCreateCharacterInstance extends BreakIteratorFactoryMethod
{
    public function __construct() { parent::__construct('createCharacterInstance'); }
    protected function breakType(): int { return VmBreakIterator::typeCharacter(); }
}
final class BreakIteratorCreateLineInstance extends BreakIteratorFactoryMethod
{
    public function __construct() { parent::__construct('createLineInstance'); }
    protected function breakType(): int { return VmBreakIterator::typeLine(); }
}
final class BreakIteratorCreateSentenceInstance extends BreakIteratorFactoryMethod
{
    public function __construct() { parent::__construct('createSentenceInstance'); }
    protected function breakType(): int { return VmBreakIterator::typeSentence(); }
}
final class BreakIteratorCreateTitleInstance extends BreakIteratorFactoryMethod
{
    public function __construct() { parent::__construct('createTitleInstance'); }
    // ICU title = word iterator in many builds; map to word for v1.
    protected function breakType(): int { return VmBreakIterator::typeWord(); }
}

final class BreakIteratorSetText extends VmClassMethod
{
    public function __construct() { parent::__construct('setText'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc !== 2) {
            throw new \ArgumentCountError(sprintf('IntlBreakIterator::setText() expects exactly 1 argument, %d given', max(0, $argc - 1)));
        }
        $obj = VmBreakIterator::requireBreakIterator($frame, $frame->calledArgs[0]);
        $textArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $textArg->type) {
            throw new \TypeError('IntlBreakIterator::setText(): Argument #1 ($text) must be of type string');
        }
        $ok = VmBreakIterator::setText($obj, $textArg->toString());
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class BreakIteratorGetText extends VmClassMethod
{
    public function __construct() { parent::__construct('getText'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requireBreakIterator($frame, $frame->calledArgs[0]);
        $text = VmBreakIterator::getText($obj);
        if (null !== $frame->returnVar) {
            null === $text ? $frame->returnVar->null() : $frame->returnVar->string($text);
        }
    }
}

final class BreakIteratorFirst extends VmClassMethod
{
    public function __construct() { parent::__construct('first'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requireBreakIterator($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmBreakIterator::first($obj));
        }
    }
}

final class BreakIteratorLast extends VmClassMethod
{
    public function __construct() { parent::__construct('last'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requireBreakIterator($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmBreakIterator::last($obj));
        }
    }
}

final class BreakIteratorNext extends VmClassMethod
{
    public function __construct() { parent::__construct('next'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $obj = VmBreakIterator::requireBreakIterator($frame, $frame->calledArgs[0]);
        $offset = null;
        if ($argc >= 2) {
            $offset = (int) $frame->calledArgs[1]->resolveIndirect()->toInt();
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmBreakIterator::nextPos($obj, $offset));
        }
    }
}

final class BreakIteratorPrevious extends VmClassMethod
{
    public function __construct() { parent::__construct('previous'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requireBreakIterator($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmBreakIterator::previousPos($obj));
        }
    }
}

final class BreakIteratorCurrent extends VmClassMethod
{
    public function __construct() { parent::__construct('current'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requireBreakIterator($frame, $frame->calledArgs[0]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmBreakIterator::currentPos($obj));
        }
    }
}

final class BreakIteratorGetPartsIterator extends VmClassMethod
{
    public function __construct() { parent::__construct('getPartsIterator'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requireBreakIterator($frame, $frame->calledArgs[0]);
        $ctx = $frame->vmContext ?? null;
        if (null === $ctx) {
            throw new \LogicException('getPartsIterator requires VM context');
        }
        $parts = VmBreakIterator::createPartsIterator($ctx, $obj);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($parts);
        }
    }
}

final class PartsIteratorCurrent extends VmClassMethod
{
    public function __construct() { parent::__construct('current'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requirePartsIterator($frame, $frame->calledArgs[0]);
        $st = VmBreakIterator::stateRef($obj);
        $parts = $st['parts'] ?? [];
        $idx = (int) ($st['index'] ?? 0);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($parts[$idx] ?? '');
        }
    }
}

final class PartsIteratorKey extends VmClassMethod
{
    public function __construct() { parent::__construct('key'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requirePartsIterator($frame, $frame->calledArgs[0]);
        $st = VmBreakIterator::stateRef($obj);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int((int) ($st['index'] ?? 0));
        }
    }
}

final class PartsIteratorNext extends VmClassMethod
{
    public function __construct() { parent::__construct('next'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requirePartsIterator($frame, $frame->calledArgs[0]);
        $st = &VmBreakIterator::stateRef($obj);
        $st['index'] = (int) ($st['index'] ?? 0) + 1;
    }
}

final class PartsIteratorRewind extends VmClassMethod
{
    public function __construct() { parent::__construct('rewind'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requirePartsIterator($frame, $frame->calledArgs[0]);
        $st = &VmBreakIterator::stateRef($obj);
        $st['index'] = 0;
    }
}

final class PartsIteratorValid extends VmClassMethod
{
    public function __construct() { parent::__construct('valid'); }
    public function execute(Frame $frame): void
    {
        $obj = VmBreakIterator::requirePartsIterator($frame, $frame->calledArgs[0]);
        $st = VmBreakIterator::stateRef($obj);
        $parts = $st['parts'] ?? [];
        $idx = (int) ($st['index'] ?? 0);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($idx >= 0 && $idx < \count($parts));
        }
    }
}
