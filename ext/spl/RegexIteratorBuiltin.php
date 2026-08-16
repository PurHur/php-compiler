<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmPreg;
use PHPCompiler\ext\standard\VmPregCompileWarn;
use PHPCompiler\ext\standard\VmPregMatches;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * RegexIterator — regex filter over inner iterator (php-src ext/spl/spl_iterators.c; #15152).
 */
final class RegexIteratorBuiltin
{
    public const CLASS_LC = 'regexiterator';

    public const USE_KEY = 1;

    public const INVERT_MATCH = 2;

    public const MATCH = 0;

    public const GET_MATCH = 1;

    public const ALL_MATCHES = 2;

    public const SPLIT = 3;

    public const REPLACE = 4;

    /** @var array<int, array{regex: string, mode: int, flags: int, pregFlags: int, replacement: string, cached: ?Variable}> */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        FilterIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RegexIterator');
        $entry->parentLc = FilterIteratorBuiltin::CLASS_LC;
        foreach (['OuterIterator', 'Traversable', 'Iterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        SplClassConstants::registerIntConstants($entry, [
            'USE_KEY' => self::USE_KEY,
            'INVERT_MATCH' => self::INVERT_MATCH,
            'MATCH' => self::MATCH,
            'GET_MATCH' => self::GET_MATCH,
            'ALL_MATCHES' => self::ALL_MATCHES,
            'SPLIT' => self::SPLIT,
            'REPLACE' => self::REPLACE,
        ]);

        // php-src stub: public ?string $replacement = null (#20153).
        if (!self::hasDeclaredReplacement($entry)) {
            $nullDefault = new Variable(Variable::TYPE_NULL);
            $strProto = new Variable(Variable::TYPE_STRING);
            $entry->properties[] = new ClassProperty(
                'replacement',
                $nullDefault,
                $strProto,
                false,
                $pub,
                self::CLASS_LC
            );
        }

        $entry->constructor = new RegexIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['accept'] = new RegexIteratorAccept();
        // php-src — public function accept(): bool (#28560).
        $entry->methodVisibility['accept'] = $pub;
        foreach ([
            'rewind' => RegexIteratorRewind::class,
            'valid' => RegexIteratorValid::class,
            'current' => RegexIteratorCurrent::class,
            'key' => RegexIteratorKey::class,
            'next' => RegexIteratorNext::class,
            'getinneriterator' => RegexIteratorGetInnerIterator::class,
            'getregex' => RegexIteratorGetRegex::class,
            'getmode' => RegexIteratorGetMode::class,
            'setmode' => RegexIteratorSetMode::class,
            'getflags' => RegexIteratorGetFlags::class,
            'setflags' => RegexIteratorSetFlags::class,
            'getpregflags' => RegexIteratorGetPregFlags::class,
            'setpregflags' => RegexIteratorSetPregFlags::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';
        $entry->methodNames['getregex'] = 'getRegex';
        $entry->methodNames['getmode'] = 'getMode';
        $entry->methodNames['setmode'] = 'setMode';
        $entry->methodNames['getflags'] = 'getFlags';
        $entry->methodNames['setflags'] = 'setFlags';
        $entry->methodNames['getpregflags'] = 'getPregFlags';
        $entry->methodNames['setpregflags'] = 'setPregFlags';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;

        RecursiveRegexIteratorBuiltin::registerClass($ctx);
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['rewind'], $entry->methods['accept'], $entry->methods['__construct'])
            && self::hasDeclaredReplacement($entry);
    }

    private static function hasDeclaredReplacement(ClassEntry $entry): bool
    {
        return self::entryHasProperty($entry, 'replacement');
    }

    /** @internal Shared by RecursiveRegexIterator property inheritance (#20153). */
    public static function entryHasProperty(ClassEntry $entry, string $name): bool
    {
        foreach ($entry->properties as $prop) {
            if ($name === $prop->name) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-src RegexIterator::__construct — compile pattern before store (#31511).
     *
     * @throws \InvalidArgumentException Zend-shaped delimiter/compile failure
     */
    public static function assertPatternCompiles(string $pattern, string $methodLabel): void
    {
        $message = VmPregCompileWarn::compileWarningMessage($pattern);
        if (null === $message) {
            return;
        }

        throw new \InvalidArgumentException($methodLabel.': '.$message);
    }

    public static function initState(
        ObjectEntry $object,
        string $regex,
        int $mode,
        int $flags,
        int $pregFlags
    ): void {
        self::validateMode($mode);
        self::$state[$object->id] = [
            'regex' => $regex,
            'mode' => $mode,
            'flags' => $flags,
            'pregFlags' => $pregFlags,
            'replacement' => '',
            'cached' => null,
        ];
    }

    public static function fetch(Frame $frame, ObjectEntry $object): void
    {
        $inner = SplDualIteratorStorage::inner($object);
        while (true) {
            $valid = SplDualIteratorStorage::callInner($frame, $inner, 'valid')->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $valid->type || !$valid->toBool()) {
                self::clearCached($object);

                return;
            }
            // php-src spl_filter_it_fetch — invoke accept on object's ce so RRI override runs (#20152).
            $accepted = self::invokeAccept($frame, $object);
            if ($accepted) {
                return;
            }
            self::clearCached($object);
            SplDualIteratorStorage::callInner($frame, $inner, 'next');
        }
    }

    /** Dispatch accept() via instance method (RecursiveRegexIterator overrides). */
    public static function invokeAccept(Frame $frame, ObjectEntry $object): bool
    {
        if (null === $frame->vmContext || null === $frame->vmContext->runtime) {
            throw new \LogicException('RegexIterator::accept() requires VM runtime');
        }
        $result = $frame->vmContext->runtime->vm->invokeInstanceMethod($object, 'accept')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    public static function callAccept(Frame $frame, ObjectEntry $object): bool
    {
        $state = self::requireState($object);
        $inner = SplDualIteratorStorage::inner($object);
        $current = SplDualIteratorStorage::callInner($frame, $inner, 'current')->resolveIndirect();
        if (Variable::TYPE_NULL === $current->type) {
            return false;
        }

        $useKey = 0 !== ($state['flags'] & self::USE_KEY);
        if ($useKey) {
            $subjectVar = SplDualIteratorStorage::callInner($frame, $inner, 'key')->resolveIndirect();
        } else {
            if (Variable::TYPE_ARRAY === $current->type) {
                return false;
            }
            $subjectVar = $current;
        }

        try {
            $subject = VmReflection::stringArg($subjectVar, 'RegexIterator::accept()', 0);
        } catch (\TypeError) {
            return false;
        }

        $accepted = self::acceptSubject($frame, $object, $state, $subject, $useKey);
        if (0 !== ($state['flags'] & self::INVERT_MATCH)) {
            $accepted = !$accepted;
        }

        return $accepted;
    }

    /**
     * RecursiveRegexIterator::accept — non-empty arrays accepted so RII can descend (#20152).
     * php-src: PHP_METHOD(RecursiveRegexIterator, accept)
     */
    public static function callAcceptRecursive(Frame $frame, ObjectEntry $object): bool
    {
        $inner = SplDualIteratorStorage::inner($object);
        $current = SplDualIteratorStorage::callInner($frame, $inner, 'current')->resolveIndirect();
        if (Variable::TYPE_NULL === $current->type) {
            return false;
        }
        if (Variable::TYPE_ARRAY === $current->type) {
            return $current->toArray()->getNumElements() > 0;
        }

        return self::callAccept($frame, $object);
    }

    /** @param array{regex: string, mode: int, flags: int, pregFlags: int, replacement: string, cached: ?Variable} $state */
    private static function acceptSubject(
        Frame $frame,
        ObjectEntry $object,
        array $state,
        string $subject,
        bool $useKey
    ): bool {
        $regex = $state['regex'];
        $pregFlags = $state['pregFlags'];

        return match ($state['mode']) {
            self::MATCH => self::acceptMatch($regex, $subject, $pregFlags),
            self::GET_MATCH => self::acceptGetMatch($object, $regex, $subject, $pregFlags),
            self::ALL_MATCHES => self::acceptAllMatches($object, $regex, $subject, $pregFlags),
            self::SPLIT => self::acceptSplit($object, $regex, $subject, $pregFlags),
            self::REPLACE => self::acceptReplace($object, $regex, $subject, $pregFlags),
            default => false,
        };
    }

    private static function acceptMatch(string $regex, string $subject, int $pregFlags): bool
    {
        $result = VmPreg::pregMatch($regex, $subject, $matches, $pregFlags);

        return false !== $result && $result > 0;
    }

    private static function acceptGetMatch(
        ObjectEntry $object,
        string $regex,
        string $subject,
        int $pregFlags
    ): bool {
        $matches = [];
        $count = VmPreg::pregMatch($regex, $subject, $matches, $pregFlags);
        if (false === $count || $count <= 0) {
            self::clearCached($object);

            return false;
        }
        $var = new Variable();
        $var->array(VmPregMatches::hostMatchesToHashTable($matches, $pregFlags));
        self::setCached($object, $var);

        return true;
    }

    private static function acceptAllMatches(
        ObjectEntry $object,
        string $regex,
        string $subject,
        int $pregFlags
    ): bool {
        $matches = [];
        $count = VmPreg::pregMatchAll(
            $regex,
            $subject,
            $matches,
            $pregFlags | StdlibConstants::PREG_PATTERN_ORDER
        );
        if (false === $count || $count <= 0) {
            self::clearCached($object);

            return false;
        }
        $var = new Variable();
        $var->array(VmPregMatches::hostMatchAllToHashTable($matches, $pregFlags | StdlibConstants::PREG_PATTERN_ORDER));
        self::setCached($object, $var);

        return true;
    }

    private static function acceptSplit(
        ObjectEntry $object,
        string $regex,
        string $subject,
        int $pregFlags
    ): bool {
        $parts = VmPreg::pregSplit($regex, $subject, -1, $pregFlags);
        if (false === $parts || \count($parts) <= 1) {
            self::clearCached($object);

            return false;
        }
        $var = new Variable();
        $var->array(VmPreg::splitPartsToHashTable($parts, $pregFlags));
        self::setCached($object, $var);

        return true;
    }

    private static function acceptReplace(
        ObjectEntry $object,
        string $regex,
        string $subject,
        int $pregFlags
    ): bool {
        $replacement = self::replacementString($object);
        $count = 0;
        $result = VmPreg::pregReplace($regex, $replacement, $subject, -1, $count);
        if (false === $result || !\is_string($result) || $count <= 0) {
            self::clearCached($object);

            return false;
        }
        $var = new Variable();
        $var->string($result);
        self::setCached($object, $var);

        return true;
    }

    private static function replacementString(ObjectEntry $object): string
    {
        $prop = $object->getProperty('replacement');
        $resolved = $prop->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return self::requireState($object)['replacement'];
        }

        return VmReflection::stringArg($resolved, 'RegexIterator::accept()', 0);
    }

    public static function currentValue(Frame $frame, ObjectEntry $object): Variable
    {
        $cached = self::cached($object);
        if (null !== $cached) {
            return $cached;
        }

        return SplDualIteratorStorage::currentSimple($frame, $object);
    }

    /** @return array{regex: string, mode: int, flags: int, pregFlags: int, replacement: string, cached: ?Variable} */
    public static function requireState(ObjectEntry $object): array
    {
        if (!isset(self::$state[$object->id])) {
            throw new \LogicException('RegexIterator state missing');
        }

        return self::$state[$object->id];
    }

    private static function cached(ObjectEntry $object): ?Variable
    {
        return self::$state[$object->id]['cached'] ?? null;
    }

    private static function setCached(ObjectEntry $object, Variable $value): void
    {
        self::$state[$object->id]['cached'] = $value;
    }

    private static function clearCached(ObjectEntry $object): void
    {
        if (isset(self::$state[$object->id])) {
            self::$state[$object->id]['cached'] = null;
        }
    }

    /**
     * php-src zim_RegexIterator_setMode / __construct mode range check.
     * Callers pass the Zend-shaped method+arg citation (#31573).
     */
    public static function validateMode(
        int $mode,
        string $where = 'RegexIterator::__construct(): Argument #3 ($mode)'
    ): void {
        if ($mode < self::MATCH || $mode > self::REPLACE) {
            throw new \ValueError(
                $where.' must be RegexIterator::MATCH, '
                .'RegexIterator::GET_MATCH, RegexIterator::ALL_MATCHES, RegexIterator::SPLIT, '
                .'or RegexIterator::REPLACE'
            );
        }
    }

    public static function setModeValue(ObjectEntry $object, int $mode): void
    {
        self::validateMode($mode, 'RegexIterator::setMode(): Argument #1 ($mode)');
        self::$state[$object->id]['mode'] = $mode;
    }

    public static function setFlagsValue(ObjectEntry $object, int $flags): void
    {
        self::$state[$object->id]['flags'] = $flags;
    }

    public static function setPregFlagsValue(ObjectEntry $object, int $pregFlags): void
    {
        self::$state[$object->id]['pregFlags'] = $pregFlags;
    }

    public static function typeLabelFor(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}

final class RegexIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::__construct()'
        );
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'RegexIterator::__construct() expects at least 2 arguments, '
                .($argc - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('RegexIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1],
            'RegexIterator::__construct',
            'Iterator'
        );
        SplDualIteratorStorage::initSimple($object, $inner);

        $regex = VmReflection::stringArg($frame->calledArgs[2], 'RegexIterator::__construct() pattern', 2);
        $mode = RegexIteratorBuiltin::MATCH;
        $flags = 0;
        $pregFlags = 0;
        if ($argc >= 4) {
            $modeVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $modeVar->type) {
                throw new \TypeError(
                    'RegexIterator::__construct(): Argument #3 ($mode) must be of type int, '
                    .RegexIteratorBuiltin::typeLabelFor($modeVar).' given'
                );
            }
            $mode = $modeVar->toInt();
        }
        if ($argc >= 5) {
            $flagsVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \TypeError(
                    'RegexIterator::__construct(): Argument #4 ($flags) must be of type int, '
                    .RegexIteratorBuiltin::typeLabelFor($flagsVar).' given'
                );
            }
            $flags = $flagsVar->toInt();
        }
        if ($argc >= 6) {
            $pregVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $pregVar->type) {
                throw new \TypeError(
                    'RegexIterator::__construct(): Argument #5 ($pregFlags) must be of type int, '
                    .RegexIteratorBuiltin::typeLabelFor($pregVar).' given'
                );
            }
            $pregFlags = $pregVar->toInt();
        }

        RegexIteratorBuiltin::validateMode($mode);
        RegexIteratorBuiltin::assertPatternCompiles($regex, 'RegexIterator::__construct()');
        RegexIteratorBuiltin::initState($object, $regex, $mode, $flags, $pregFlags);
    }
}

final class RegexIteratorAccept extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('accept');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::accept()'
        );
        SplIteratorSupport::setReturnBool($frame, RegexIteratorBuiltin::callAccept($frame, $object));
    }
}

final class RegexIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::rewind()'
        );
        SplDualIteratorStorage::rewindSimple($frame, $object);
        RegexIteratorBuiltin::fetch($frame, $object);
    }
}

final class RegexIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::next()'
        );
        $inner = SplDualIteratorStorage::inner($object);
        SplDualIteratorStorage::callInner($frame, $inner, 'next');
        RegexIteratorBuiltin::fetch($frame, $object);
    }
}

final class RegexIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, SplDualIteratorStorage::validSimple($frame, $object));
    }
}

final class RegexIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom($frame, RegexIteratorBuiltin::currentValue($frame, $object));
    }
}

final class RegexIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::key()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDualIteratorStorage::keySimple($frame, $object));
    }
}

final class RegexIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::getInnerIterator()'
        );
        // Inherited zim_IteratorIterator_getInnerIterator — ACE cites IteratorIterator (#30949).
        $this->requireExactUserArgCount($frame, 'IteratorIterator::getInnerIterator', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(SplDualIteratorStorage::inner($object));
    }
}

final class RegexIteratorGetRegex extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getRegex');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::getRegex()'
        );
        // php-src zim_RegexIterator_getRegex — ZEND_PARSE_PARAMETERS_NONE (#30949).
        $this->requireExactUserArgCount($frame, 'RegexIterator::getRegex', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(RegexIteratorBuiltin::requireState($object)['regex']);
    }
}

final class RegexIteratorGetMode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMode');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::getMode()'
        );
        // php-src zim_RegexIterator_getMode — ZEND_PARSE_PARAMETERS_NONE (#31594).
        $this->requireExactUserArgCount($frame, 'RegexIterator::getMode', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(RegexIteratorBuiltin::requireState($object)['mode']);
    }
}

final class RegexIteratorSetMode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setMode');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::setMode()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'RegexIterator::setMode() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $modeVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $modeVar->type) {
            throw new \TypeError(
                'RegexIterator::setMode(): Argument #1 ($mode) must be of type int, '
                .RegexIteratorBuiltin::typeLabelFor($modeVar).' given'
            );
        }
        RegexIteratorBuiltin::setModeValue($object, $modeVar->toInt());
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}

final class RegexIteratorGetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::getFlags()'
        );
        // php-src zim_RegexIterator_getFlags — ZEND_PARSE_PARAMETERS_NONE (#31594).
        $this->requireExactUserArgCount($frame, 'RegexIterator::getFlags', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(RegexIteratorBuiltin::requireState($object)['flags']);
    }
}

final class RegexIteratorSetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::setFlags()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'RegexIterator::setFlags() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $flagsVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $flagsVar->type) {
            throw new \TypeError(
                'RegexIterator::setFlags(): Argument #1 ($flags) must be of type int, '
                .RegexIteratorBuiltin::typeLabelFor($flagsVar).' given'
            );
        }
        RegexIteratorBuiltin::setFlagsValue($object, $flagsVar->toInt());
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}

final class RegexIteratorGetPregFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPregFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::getPregFlags()'
        );
        // php-src zim_RegexIterator_getPregFlags — ZEND_PARSE_PARAMETERS_NONE (#31594).
        $this->requireExactUserArgCount($frame, 'RegexIterator::getPregFlags', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(RegexIteratorBuiltin::requireState($object)['pregFlags']);
    }
}

final class RegexIteratorSetPregFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setPregFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RegexIteratorBuiltin::CLASS_LC,
            'RegexIterator::setPregFlags()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'RegexIterator::setPregFlags() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $pregVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $pregVar->type) {
            throw new \TypeError(
                'RegexIterator::setPregFlags(): Argument #1 ($preg_flags) must be of type int, '
                .RegexIteratorBuiltin::typeLabelFor($pregVar).' given'
            );
        }
        RegexIteratorBuiltin::setPregFlagsValue($object, $pregVar->toInt());
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}

/** RecursiveRegexIterator — regex filter over recursive inner iterator (php-src ext/spl/spl_iterators.c; #6693). */
final class RecursiveRegexIteratorBuiltin
{
    public const CLASS_LC = 'recursiveregexiterator';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['accept'])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RecursiveRegexIterator');
        $entry->parentLc = RegexIteratorBuiltin::CLASS_LC;
        // Zend rematerializes Iterator-first flattened ce->interfaces on the subclass
        // (RegexIterator parent stays OuterIterator-first; #25823).
        $entry->interfaces = [];
        foreach (['iterator', 'traversable', 'outeriterator', 'recursiveiterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new RecursiveRegexIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['haschildren'] = new RecursiveRegexIteratorHasChildren();
        $entry->methodVisibility['haschildren'] = $pub;
        $entry->methodNames['haschildren'] = 'hasChildren';
        $entry->methods['getchildren'] = new RecursiveRegexIteratorGetChildren();
        $entry->methodVisibility['getchildren'] = $pub;
        $entry->methodNames['getchildren'] = 'getChildren';
        // php-src PHP_METHOD(RecursiveRegexIterator, accept) — arrays descend via RII (#20152).
        $entry->methods['accept'] = new RecursiveRegexIteratorAccept();
        $entry->methodVisibility['accept'] = $pub;

        // Inherit declared instance properties from RegexIterator (e.g. $replacement, #20153).
        $parent = $ctx->classes[RegexIteratorBuiltin::CLASS_LC] ?? null;
        if (null !== $parent) {
            foreach ($parent->properties as $prop) {
                if (!RegexIteratorBuiltin::entryHasProperty($entry, $prop->name)) {
                    $entry->properties[] = $prop;
                }
            }
        }

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function createFromInnerTemplate(
        Context $ctx,
        ObjectEntry $childInner,
        ObjectEntry $template
    ): Variable {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('RecursiveRegexIterator is not registered in this compiler build');
        }
        $state = RegexIteratorBuiltin::requireState($template);
        $object = new ObjectEntry($class);
        $object->constructed = true;
        SplDualIteratorStorage::initSimple($object, $childInner);
        RegexIteratorBuiltin::initState(
            $object,
            $state['regex'],
            $state['mode'],
            $state['flags'],
            $state['pregFlags']
        );
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }
}

final class RecursiveRegexIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveRegexIteratorBuiltin::CLASS_LC,
            'RecursiveRegexIterator::__construct()'
        );
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'RecursiveRegexIterator::__construct() expects at least 2 arguments, '
                .($argc - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('RecursiveRegexIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveRecursiveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        SplDualIteratorStorage::initSimple($object, $inner);

        $regex = VmReflection::stringArg($frame->calledArgs[2], 'RecursiveRegexIterator::__construct() pattern', 2);
        $mode = RegexIteratorBuiltin::MATCH;
        $flags = 0;
        $pregFlags = 0;
        if ($argc >= 4) {
            $modeVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $modeVar->type) {
                throw new \TypeError(
                    'RecursiveRegexIterator::__construct(): Argument #3 ($mode) must be of type int, '
                    .RegexIteratorBuiltin::typeLabelFor($modeVar).' given'
                );
            }
            $mode = $modeVar->toInt();
        }
        if ($argc >= 5) {
            $flagsVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \TypeError(
                    'RecursiveRegexIterator::__construct(): Argument #4 ($flags) must be of type int, '
                    .RegexIteratorBuiltin::typeLabelFor($flagsVar).' given'
                );
            }
            $flags = $flagsVar->toInt();
        }
        if ($argc >= 6) {
            $pregVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $pregVar->type) {
                throw new \TypeError(
                    'RecursiveRegexIterator::__construct(): Argument #5 ($pregFlags) must be of type int, '
                    .RegexIteratorBuiltin::typeLabelFor($pregVar).' given'
                );
            }
            $pregFlags = $pregVar->toInt();
        }

        RegexIteratorBuiltin::validateMode($mode);
        RegexIteratorBuiltin::assertPatternCompiles($regex, 'RecursiveRegexIterator::__construct()');
        RegexIteratorBuiltin::initState($object, $regex, $mode, $flags, $pregFlags);
    }
}

final class RecursiveRegexIteratorHasChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveRegexIteratorBuiltin::CLASS_LC,
            'RecursiveRegexIterator::hasChildren()'
        );
        $inner = SplDualIteratorStorage::inner($object);
        $result = SplDualIteratorStorage::callInner($frame, $inner, 'hasChildren')->resolveIndirect();
        SplIteratorSupport::setReturnBool(
            $frame,
            Variable::TYPE_BOOLEAN === $result->type && $result->toBool()
        );
    }
}

final class RecursiveRegexIteratorGetChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveRegexIteratorBuiltin::CLASS_LC,
            'RecursiveRegexIterator::getChildren()'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('RecursiveRegexIterator::getChildren() requires VM context');
        }
        $inner = SplDualIteratorStorage::inner($object);
        $childInnerVar = SplDualIteratorStorage::callInner($frame, $inner, 'getChildren')->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $childInnerVar->type) {
            throw new \UnexpectedValueException('RecursiveIterator::getChildren() must return an object');
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            RecursiveRegexIteratorBuiltin::createFromInnerTemplate(
                $frame->vmContext,
                $childInnerVar->toObject(),
                $object
            )
        );
    }
}

/** php-src RecursiveRegexIterator::accept — non-empty array currents pass (#20152). */
final class RecursiveRegexIteratorAccept extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('accept');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveRegexIteratorBuiltin::CLASS_LC,
            'RecursiveRegexIterator::accept()'
        );
        SplIteratorSupport::setReturnBool(
            $frame,
            RegexIteratorBuiltin::callAcceptRecursive($frame, $object)
        );
    }
}
