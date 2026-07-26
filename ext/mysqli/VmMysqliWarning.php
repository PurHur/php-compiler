<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * mysqli_warning VM class (php-src ext/mysqli/mysqli_warning.c; #22224).
 *
 * One object holds a linked list of warning rows; next() advances the cursor
 * and refreshes public $message / $sqlstate / $errno (Zend property handlers).
 */
final class VmMysqliWarning
{
    public const CLASS_LC = 'mysqli_warning';

    /** @var array<int, MysqliWarningState> */
    private static array $store = [];

    public static function register(Context $ctx): void
    {
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('mysqli_warning');
        $entry->isInternal = true;
        $entry->isFinal = true;
        $entry->parentLc = null;

        $pub = CfgFunc::FLAG_PUBLIC;
        $priv = CfgFunc::FLAG_PRIVATE;

        self::ensureProperty($entry, 'message', Variable::TYPE_STRING, $pub);
        self::ensureProperty($entry, 'sqlstate', Variable::TYPE_STRING, $pub);
        self::ensureProperty($entry, 'errno', Variable::TYPE_INTEGER, $pub);

        $entry->constructor = new MysqliWarningConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $priv;

        $entry->methods['next'] = new MysqliWarningNext();
        $entry->methodVisibility['next'] = $pub;

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function ensureProperty(ClassEntry $entry, string $name, int $type, int $vis): void
    {
        foreach ($entry->properties as $prop) {
            if (\strtolower($prop->name) === \strtolower($name)) {
                return;
            }
        }
        $proto = new Variable($type);
        $entry->properties[] = new ClassProperty($name, null, $proto, false, $vis, self::CLASS_LC);
    }

    /**
     * Build a mysqli_warning from host \mysqli_warning linked list (#22224).
     *
     * @param object $native Host mysqli_warning (first node)
     */
    public static function fromNativeChain(Context $ctx, object $native): ObjectEntry
    {
        $rows = [];
        $cur = $native;
        do {
            $rows[] = [
                'message' => (string) ($cur->message ?? ''),
                'sqlstate' => (string) ($cur->sqlstate ?? 'HY000'),
                'errno' => (int) ($cur->errno ?? 0),
            ];
            $advanced = false;
            if (\method_exists($cur, 'next')) {
                $advanced = (bool) $cur->next();
            }
        } while ($advanced);

        return self::fromRows($ctx, $rows);
    }

    /**
     * @param list<array{message: string, sqlstate: string, errno: int}> $rows
     */
    public static function fromRows(Context $ctx, array $rows): ObjectEntry
    {
        if ($rows === []) {
            throw new \LogicException('mysqli_warning requires at least one row');
        }
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('mysqli_warning class not registered');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = new MysqliWarningState();
        $state->rows = $rows;
        $state->index = 0;
        self::$store[$entry->id] = $state;
        self::syncProps($entry);

        return $entry;
    }

    public static function next(ObjectEntry $entry): bool
    {
        if (!isset(self::$store[$entry->id])) {
            return false;
        }
        $state = self::$store[$entry->id];
        if ($state->index + 1 >= \count($state->rows)) {
            return false;
        }
        ++$state->index;
        self::syncProps($entry);

        return true;
    }

    private static function syncProps(ObjectEntry $entry): void
    {
        $state = self::$store[$entry->id];
        $row = $state->rows[$state->index];
        $entry->getProperty('message')->string($row['message']);
        $entry->getProperty('sqlstate')->string($row['sqlstate']);
        $entry->getProperty('errno')->int($row['errno']);
    }
}

/** @internal */
final class MysqliWarningState
{
    /** @var list<array{message: string, sqlstate: string, errno: int}> */
    public array $rows = [];

    public int $index = 0;
}

/** mysqli_warning::__construct() — php-src throws (private). */
final class MysqliWarningConstruct extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        throw new \Error('Cannot directly construct mysqli_warning');
    }
}

/** mysqli_warning::next() — php-src ext/mysqli/mysqli_warning.c (#22224). */
final class MysqliWarningNext extends MysqliClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'mysqli_warning::next()');
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'mysqli_warning::next() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliWarning::next($receiver));
        }
    }
}
