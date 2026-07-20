<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Register tidy + tidyNode classes (php-src ext/tidy/tidy.stub.php; #21464, #21498, #21499, #21540, #21543, #21606).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        self::registerTidy($ctx);
        self::registerTidyNode($ctx);
    }

    private static function registerTidy(Context $ctx): void
    {
        if (isset($ctx->classes[VmTidy::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $entry = new ClassEntry('tidy');
        $entry->isInternal = true;

        $nullDefault = new Variable(Variable::TYPE_NULL);
        $strProto = new Variable(Variable::TYPE_STRING);
        $entry->properties[] = new ClassProperty(
            'value',
            $nullDefault,
            $strProto,
            false,
            $pub,
            VmTidy::CLASS_LC
        );
        $entry->properties[] = new ClassProperty(
            'errorBuffer',
            new Variable(Variable::TYPE_NULL),
            $strProto,
            false,
            $pub,
            VmTidy::CLASS_LC
        );

        $clean = new TidyCleanRepair();
        $entry->methods['cleanrepair'] = $clean;
        $entry->methodVisibility['cleanrepair'] = $pub;
        $entry->methodNames['cleanrepair'] = 'cleanRepair';

        $diagnose = new TidyDiagnose();
        $entry->methods['diagnose'] = $diagnose;
        $entry->methodVisibility['diagnose'] = $pub;
        $entry->methodNames['diagnose'] = 'diagnose';

        $parseString = new TidyParseStringMethod();
        $entry->methods['parsestring'] = $parseString;
        $entry->methodVisibility['parsestring'] = $pub;
        $entry->methodNames['parsestring'] = 'parseString';

        $parseFile = new TidyParseFileMethod();
        $entry->methods['parsefile'] = $parseFile;
        $entry->methodVisibility['parsefile'] = $pub;
        $entry->methodNames['parsefile'] = 'parseFile';

        $repairString = new TidyRepairString();
        $entry->methods['repairstring'] = $repairString;
        $entry->methodVisibility['repairstring'] = $pubStatic;
        $entry->methodNames['repairstring'] = 'repairString';

        $repairFile = new TidyRepairFile();
        $entry->methods['repairfile'] = $repairFile;
        $entry->methodVisibility['repairfile'] = $pubStatic;
        $entry->methodNames['repairfile'] = 'repairFile';

        $getOpt = new TidyGetOpt();
        $entry->methods['getopt'] = $getOpt;
        $entry->methodVisibility['getopt'] = $pub;
        $entry->methodNames['getopt'] = 'getOpt';

        $getConfig = new TidyGetConfig();
        $entry->methods['getconfig'] = $getConfig;
        $entry->methodVisibility['getconfig'] = $pub;
        $entry->methodNames['getconfig'] = 'getConfig';

        $getStatus = new TidyGetStatus();
        $entry->methods['getstatus'] = $getStatus;
        $entry->methodVisibility['getstatus'] = $pub;
        $entry->methodNames['getstatus'] = 'getStatus';

        $getRelease = new TidyGetRelease();
        $entry->methods['getrelease'] = $getRelease;
        $entry->methodVisibility['getrelease'] = $pub;
        $entry->methodNames['getrelease'] = 'getRelease';

        $getHtmlVer = new TidyGetHtmlVer();
        $entry->methods['gethtmlver'] = $getHtmlVer;
        $entry->methodVisibility['gethtmlver'] = $pub;
        $entry->methodNames['gethtmlver'] = 'getHtmlVer';

        $isXhtml = new TidyIsXhtml();
        $entry->methods['isxhtml'] = $isXhtml;
        $entry->methodVisibility['isxhtml'] = $pub;
        $entry->methodNames['isxhtml'] = 'isXhtml';

        $isXml = new TidyIsXml();
        $entry->methods['isxml'] = $isXml;
        $entry->methodVisibility['isxml'] = $pub;
        $entry->methodNames['isxml'] = 'isXml';

        $root = new TidyRoot();
        $entry->methods['root'] = $root;
        $entry->methodVisibility['root'] = $pub;
        $entry->methodNames['root'] = 'root';

        $html = new TidyHtml();
        $entry->methods['html'] = $html;
        $entry->methodVisibility['html'] = $pub;
        $entry->methodNames['html'] = 'html';

        $head = new TidyHead();
        $entry->methods['head'] = $head;
        $entry->methodVisibility['head'] = $pub;
        $entry->methodNames['head'] = 'head';

        $body = new TidyBody();
        $entry->methods['body'] = $body;
        $entry->methodVisibility['body'] = $pub;
        $entry->methodNames['body'] = 'body';

        $ctx->classes[VmTidy::CLASS_LC] = $entry;
    }

    /** final class tidyNode — readonly properties + node helpers (#21543). */
    private static function registerTidyNode(Context $ctx): void
    {
        if (isset($ctx->classes[VmTidy::NODE_CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $priv = CfgFunc::FLAG_PRIVATE;
        $entry = new ClassEntry('tidyNode');
        $entry->isInternal = true;
        $entry->isFinal = true;

        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $arrProto = new Variable(Variable::TYPE_ARRAY);

        $entry->properties[] = new ClassProperty('value', null, $strProto, true, $pub, VmTidy::NODE_CLASS_LC);
        $entry->properties[] = new ClassProperty('name', null, $strProto, true, $pub, VmTidy::NODE_CLASS_LC);
        $entry->properties[] = new ClassProperty('type', null, $intProto, true, $pub, VmTidy::NODE_CLASS_LC);
        $entry->properties[] = new ClassProperty('line', null, $intProto, true, $pub, VmTidy::NODE_CLASS_LC);
        $entry->properties[] = new ClassProperty('column', null, $intProto, true, $pub, VmTidy::NODE_CLASS_LC);
        $entry->properties[] = new ClassProperty('proprietary', null, $boolProto, true, $pub, VmTidy::NODE_CLASS_LC);
        $entry->properties[] = new ClassProperty('id', $nullProto, $intProto, true, $pub, VmTidy::NODE_CLASS_LC);
        $entry->properties[] = new ClassProperty('attribute', $nullProto, $arrProto, true, $pub, VmTidy::NODE_CLASS_LC);
        $entry->properties[] = new ClassProperty('child', $nullProto, $arrProto, true, $pub, VmTidy::NODE_CLASS_LC);

        $ctor = new TidyNodeConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $priv;

        foreach ([
            'hasChildren' => new TidyNodeHasChildren(),
            'hasSiblings' => new TidyNodeHasSiblings(),
            'isComment' => new TidyNodeIsComment(),
            'isHtml' => new TidyNodeIsHtml(),
            'isText' => new TidyNodeIsText(),
            'isJste' => new TidyNodeIsJste(),
            'isAsp' => new TidyNodeIsAsp(),
            'isPhp' => new TidyNodeIsPhp(),
            'getParent' => new TidyNodeGetParent(),
            'getPreviousSibling' => new TidyNodeGetPreviousSibling(),
            'getNextSibling' => new TidyNodeGetNextSibling(),
        ] as $name => $method) {
            $lc = strtolower($name);
            $entry->methods[$lc] = $method;
            $entry->methodVisibility[$lc] = $pub;
            $entry->methodNames[$lc] = $name;
        }

        $ctx->classes[VmTidy::NODE_CLASS_LC] = $entry;
    }

    /** Static call may omit receiver; instance-style leaves tidy $this in args[0]. */
    public static function staticArgOffset(Frame $frame): int
    {
        if (\count($frame->calledArgs) < 1) {
            return 0;
        }
        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT === $first->type
            && VmTidy::CLASS_LC === strtolower($first->toObject()->class->name)) {
            return 1;
        }

        return 0;
    }
}

/** tidy::cleanRepair() — host bridge (#21464). */
final class TidyCleanRepair extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('cleanRepair');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidy::cleanRepair() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::cleanRepair() called without $this');
        }
        $ok = VmTidy::cleanRepair($self->toObject(), $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

/** tidy::diagnose() — host bridge (#21500). */
final class TidyDiagnose extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('diagnose');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidy::diagnose() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::diagnose() called without $this');
        }
        $ok = VmTidy::diagnose($self->toObject(), $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

/** tidy::parseString() — instance host bridge (#21501). */
final class TidyParseStringMethod extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parseString');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'tidy::parseString() expects at least 1 argument, '.max(0, \count($frame->calledArgs) - 1).' given'
            );
        }
        if (\count($frame->calledArgs) > 4) {
            throw new \ArgumentCountError(
                'tidy::parseString() expects at most 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::parseString() called without $this');
        }
        $html = VmTidy::htmlStringArg($frame->calledArgs[1], 'tidy::parseString', 0);
        $ok = VmTidy::parseStringInto($self->toObject(), $html, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

/** tidy::parseFile() — instance host bridge (#21501). */
final class TidyParseFileMethod extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parseFile');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'tidy::parseFile() expects at least 1 argument, '.max(0, \count($frame->calledArgs) - 1).' given'
            );
        }
        if (\count($frame->calledArgs) > 5) {
            throw new \ArgumentCountError(
                'tidy::parseFile() expects at most 4 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::parseFile() called without $this');
        }
        $filename = VmTidy::htmlStringArg($frame->calledArgs[1], 'tidy::parseFile', 0);
        $ok = VmTidy::parseFileInto($self->toObject(), $filename, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

/** tidy::repairString() — static host bridge (#21498). */
final class TidyRepairString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('repairString');
    }

    public function execute(Frame $frame): void
    {
        $offset = BuiltinClasses::staticArgOffset($frame);
        if (\count($frame->calledArgs) < $offset + 1) {
            throw new \ArgumentCountError('tidy::repairString() expects at least 1 argument, 0 given');
        }
        if (\count($frame->calledArgs) > $offset + 3) {
            throw new \ArgumentCountError(
                'tidy::repairString() expects at most 3 arguments, '.(\count($frame->calledArgs) - $offset).' given'
            );
        }
        $html = VmTidy::htmlStringArg($frame->calledArgs[$offset], 'tidy::repairString', 0);
        $repaired = VmTidy::repairString($html, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($repaired): void {
            if (false === $repaired) {
                $ret->bool(false);

                return;
            }
            $ret->string($repaired);
        });
    }
}

/** tidy::repairFile() — static host bridge (#21498). */
final class TidyRepairFile extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('repairFile');
    }

    public function execute(Frame $frame): void
    {
        $offset = BuiltinClasses::staticArgOffset($frame);
        if (\count($frame->calledArgs) < $offset + 1) {
            throw new \ArgumentCountError('tidy::repairFile() expects at least 1 argument, 0 given');
        }
        if (\count($frame->calledArgs) > $offset + 4) {
            throw new \ArgumentCountError(
                'tidy::repairFile() expects at most 4 arguments, '.(\count($frame->calledArgs) - $offset).' given'
            );
        }
        $filename = VmTidy::htmlStringArg($frame->calledArgs[$offset], 'tidy::repairFile', 0);
        $repaired = VmTidy::repairFile($filename, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($repaired): void {
            if (false === $repaired) {
                $ret->bool(false);

                return;
            }
            $ret->string($repaired);
        });
    }
}

/** tidy::getOpt() — host bridge (#21540). */
final class TidyGetOpt extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getOpt');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'tidy::getOpt() expects exactly 1 argument, '.max(0, \count($frame->calledArgs) - 1).' given'
            );
        }
        if (\count($frame->calledArgs) > 2) {
            throw new \ArgumentCountError(
                'tidy::getOpt() expects exactly 1 argument, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::getOpt() called without $this');
        }
        $option = VmTidy::htmlStringArg($frame->calledArgs[1], 'tidy::getOpt', 0);
        $val = VmTidy::getOpt($self->toObject(), $option, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($val): void {
            if (null === $val) {
                $ret->bool(false);

                return;
            }
            VmTidy::assignOptValue($ret, $val);
        });
    }
}

/** tidy::getConfig() — host bridge (#21540). */
final class TidyGetConfig extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getConfig');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidy::getConfig() called without $this');
        }
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'tidy::getConfig() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::getConfig() called without $this');
        }
        $cfg = VmTidy::getConfig($self->toObject(), $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($cfg): void {
            VmTidy::assignConfigArray($ret, null === $cfg ? [] : $cfg);
        });
    }
}

/** tidy::getStatus() — host bridge (#21540). */
final class TidyGetStatus extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getStatus');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidy::getStatus() called without $this');
        }
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'tidy::getStatus() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::getStatus() called without $this');
        }
        $status = VmTidy::getStatus($self->toObject(), $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($status): void {
            $ret->int($status);
        });
    }
}

/** tidy::getRelease() — host bridge (#21542). */
final class TidyGetRelease extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getRelease');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidy::getRelease() called without $this');
        }
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'tidy::getRelease() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $rel = VmTidy::getRelease($frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($rel): void {
            $ret->string($rel);
        });
    }
}

/** tidy::getHtmlVer() — host bridge (#21542). */
final class TidyGetHtmlVer extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getHtmlVer');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidy::getHtmlVer() called without $this');
        }
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'tidy::getHtmlVer() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::getHtmlVer() called without $this');
        }
        $ver = VmTidy::getHtmlVer($self->toObject(), $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ver): void {
            $ret->int($ver);
        });
    }
}

/** tidy::isXhtml() — host bridge (#21542). */
final class TidyIsXhtml extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isXhtml');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidy::isXhtml() called without $this');
        }
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'tidy::isXhtml() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::isXhtml() called without $this');
        }
        $ok = VmTidy::isXhtml($self->toObject(), $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

/** tidy::isXml() — host bridge (#21542). */
final class TidyIsXml extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isXml');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidy::isXml() called without $this');
        }
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'tidy::isXml() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::isXml() called without $this');
        }
        $ok = VmTidy::isXml($self->toObject(), $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

/** Shared 0-arg tidy document-node getter (#21543). */
abstract class TidyDocumentNodeMethod extends VmClassMethod
{
    abstract protected function nodeKind(): string;

    public function execute(Frame $frame): void
    {
        $kind = $this->nodeKind();
        $method = $kind;
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidy::'.$method.'() called without $this');
        }
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'tidy::'.$method.'() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidy::'.$method.'() called without $this');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('tidy::'.$method.'() requires a VM context');
        }
        $node = VmTidy::getDocumentNode($ctx, $self->toObject(), $kind, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($node): void {
            VmTidy::assignNullableNode($ret, $node);
        });
    }
}

/** tidy::root() — host bridge (#21543). */
final class TidyRoot extends TidyDocumentNodeMethod
{
    public function __construct()
    {
        parent::__construct('root');
    }

    protected function nodeKind(): string
    {
        return 'root';
    }
}

/** tidy::html() — host bridge (#21543). */
final class TidyHtml extends TidyDocumentNodeMethod
{
    public function __construct()
    {
        parent::__construct('html');
    }

    protected function nodeKind(): string
    {
        return 'html';
    }
}

/** tidy::head() — host bridge (#21543). */
final class TidyHead extends TidyDocumentNodeMethod
{
    public function __construct()
    {
        parent::__construct('head');
    }

    protected function nodeKind(): string
    {
        return 'head';
    }
}

/** tidy::body() — host bridge (#21543). */
final class TidyBody extends TidyDocumentNodeMethod
{
    public function __construct()
    {
        parent::__construct('body');
    }

    protected function nodeKind(): string
    {
        return 'body';
    }
}

/** tidyNode private constructor — cannot construct from userland (#21543). */
final class TidyNodeConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        throw new \Error('Call to private tidyNode::__construct() from invalid context');
    }
}

/** Shared 0-arg tidyNode bool method (#21543). */
abstract class TidyNodeBoolMethod extends VmClassMethod
{
    abstract protected function hostMethod(): string;

    public function execute(Frame $frame): void
    {
        $method = $this->hostMethod();
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidyNode::'.$method.'() called without $this');
        }
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'tidyNode::'.$method.'() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidyNode::'.$method.'() called without $this');
        }
        $ok = VmTidy::nodeBoolMethod($self->toObject(), $method, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }
}

/** tidyNode::hasChildren() (#21543). */
final class TidyNodeHasChildren extends TidyNodeBoolMethod
{
    public function __construct()
    {
        parent::__construct('hasChildren');
    }

    protected function hostMethod(): string
    {
        return 'hasChildren';
    }
}

/** tidyNode::hasSiblings() (#21543). */
final class TidyNodeHasSiblings extends TidyNodeBoolMethod
{
    public function __construct()
    {
        parent::__construct('hasSiblings');
    }

    protected function hostMethod(): string
    {
        return 'hasSiblings';
    }
}

/** tidyNode::isComment() (#21543). */
final class TidyNodeIsComment extends TidyNodeBoolMethod
{
    public function __construct()
    {
        parent::__construct('isComment');
    }

    protected function hostMethod(): string
    {
        return 'isComment';
    }
}

/** tidyNode::isHtml() (#21606). */
final class TidyNodeIsHtml extends TidyNodeBoolMethod
{
    public function __construct()
    {
        parent::__construct('isHtml');
    }

    protected function hostMethod(): string
    {
        return 'isHtml';
    }
}

/** tidyNode::isText() (#21543). */
final class TidyNodeIsText extends TidyNodeBoolMethod
{
    public function __construct()
    {
        parent::__construct('isText');
    }

    protected function hostMethod(): string
    {
        return 'isText';
    }
}

/** tidyNode::isJste() (#21606). */
final class TidyNodeIsJste extends TidyNodeBoolMethod
{
    public function __construct()
    {
        parent::__construct('isJste');
    }

    protected function hostMethod(): string
    {
        return 'isJste';
    }
}

/** tidyNode::isAsp() (#21606). */
final class TidyNodeIsAsp extends TidyNodeBoolMethod
{
    public function __construct()
    {
        parent::__construct('isAsp');
    }

    protected function hostMethod(): string
    {
        return 'isAsp';
    }
}

/** tidyNode::isPhp() (#21606). */
final class TidyNodeIsPhp extends TidyNodeBoolMethod
{
    public function __construct()
    {
        parent::__construct('isPhp');
    }

    protected function hostMethod(): string
    {
        return 'isPhp';
    }
}

/** Shared 0-arg tidyNode related-node getter (#21543). */
abstract class TidyNodeRelatedMethod extends VmClassMethod
{
    abstract protected function hostMethod(): string;

    public function execute(Frame $frame): void
    {
        $method = $this->hostMethod();
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('tidyNode::'.$method.'() called without $this');
        }
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'tidyNode::'.$method.'() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException('tidyNode::'.$method.'() called without $this');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('tidyNode::'.$method.'() requires a VM context');
        }
        $node = VmTidy::nodeRelated($ctx, $self->toObject(), $method, $frame);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($node): void {
            VmTidy::assignNullableNode($ret, $node);
        });
    }
}

/** tidyNode::getParent() (#21543). */
final class TidyNodeGetParent extends TidyNodeRelatedMethod
{
    public function __construct()
    {
        parent::__construct('getParent');
    }

    protected function hostMethod(): string
    {
        return 'getParent';
    }
}

/** tidyNode::getPreviousSibling() (#21543). */
final class TidyNodeGetPreviousSibling extends TidyNodeRelatedMethod
{
    public function __construct()
    {
        parent::__construct('getPreviousSibling');
    }

    protected function hostMethod(): string
    {
        return 'getPreviousSibling';
    }
}

/** tidyNode::getNextSibling() (#21543). */
final class TidyNodeGetNextSibling extends TidyNodeRelatedMethod
{
    public function __construct()
    {
        parent::__construct('getNextSibling');
    }

    protected function hostMethod(): string
    {
        return 'getNextSibling';
    }
}
