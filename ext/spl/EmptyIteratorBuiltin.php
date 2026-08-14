<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCfg\Func as CfgFunc;

/** EmptyIterator — always-invalid iterator (php-src ext/spl/spl_iterators.c; #6593). */
final class EmptyIteratorBuiltin
{
    public const CLASS_LC = 'emptyiterator';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('EmptyIterator');
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }

        $entry->constructor = new EmptyIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['current'] = new EmptyIteratorCurrent();
        $entry->methodVisibility['current'] = $pub;
        $entry->methods['key'] = new EmptyIteratorKey();
        $entry->methodVisibility['key'] = $pub;
        $entry->methods['next'] = new EmptyIteratorNext();
        $entry->methodVisibility['next'] = $pub;
        $entry->methods['rewind'] = new EmptyIteratorRewind();
        $entry->methodVisibility['rewind'] = $pub;
        $entry->methods['valid'] = new EmptyIteratorValid();
        $entry->methodVisibility['valid'] = $pub;

        $ctx->classes[self::CLASS_LC] = $entry;
    }
}

final class EmptyIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, EmptyIteratorBuiltin::CLASS_LC, 'EmptyIterator::__construct()');
    }
}

final class EmptyIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, EmptyIteratorBuiltin::CLASS_LC, 'EmptyIterator::current()');
        // php-src zim_EmptyIterator_* — ZEND_PARSE_PARAMETERS_NONE (#30949).
        $this->requireExactUserArgCount($frame, 'EmptyIterator::current', 0);
        throw new \BadMethodCallException('Accessing the value of an EmptyIterator');
    }
}

final class EmptyIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, EmptyIteratorBuiltin::CLASS_LC, 'EmptyIterator::key()');
        // php-src zim_EmptyIterator_* — ZEND_PARSE_PARAMETERS_NONE (#30949).
        $this->requireExactUserArgCount($frame, 'EmptyIterator::key', 0);
        throw new \BadMethodCallException('Accessing the key of an EmptyIterator');
    }
}

final class EmptyIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, EmptyIteratorBuiltin::CLASS_LC, 'EmptyIterator::next()');
        // php-src zim_EmptyIterator_* — ZEND_PARSE_PARAMETERS_NONE (#30949).
        $this->requireExactUserArgCount($frame, 'EmptyIterator::next', 0);
    }
}

final class EmptyIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, EmptyIteratorBuiltin::CLASS_LC, 'EmptyIterator::rewind()');
        // php-src zim_EmptyIterator_* — ZEND_PARSE_PARAMETERS_NONE (#30949).
        $this->requireExactUserArgCount($frame, 'EmptyIterator::rewind', 0);
    }
}

final class EmptyIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, EmptyIteratorBuiltin::CLASS_LC, 'EmptyIterator::valid()');
        // php-src zim_EmptyIterator_* — ZEND_PARSE_PARAMETERS_NONE (#30949).
        $this->requireExactUserArgCount($frame, 'EmptyIterator::valid', 0);
        SplIteratorSupport::setReturnBool($frame, false);
    }
}
