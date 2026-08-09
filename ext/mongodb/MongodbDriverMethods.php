<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** MongoDB\Driver\{Command,ReadPreference,WriteConcern} method handlers (#27875). */
final class CommandConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('MongoDB\\Driver\\Command::__construct() expects at least 1 argument');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\Driver\\Command::__construct() must be called on Command');
        }
        $document = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $document->type && Variable::TYPE_OBJECT !== $document->type) {
            throw new \TypeError(\sprintf(
                'MongoDB\\Driver\\Command::__construct(): Argument #1 ($document) must be of type array|object, %s given',
                VmMongodb::typeLabel($document)
            ));
        }
        if (isset($frame->calledArgs[2])) {
            $opt = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $opt->type && Variable::TYPE_ARRAY !== $opt->type) {
                throw new \TypeError(\sprintf(
                    'MongoDB\\Driver\\Command::__construct(): Argument #2 ($options) must be of type ?array, %s given',
                    VmMongodb::typeLabel($opt)
                ));
            }
        }
        VmMongodbTypes::initCommand($var->toObject());
    }
}

final class ReadPreferenceConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('MongoDB\\Driver\\ReadPreference::__construct() expects at least 1 argument');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\Driver\\ReadPreference::__construct() must be called on ReadPreference');
        }
        [$mode, $modeString] = VmMongodbTypes::resolveReadPreferenceMode($frame->calledArgs[1]);
        if (isset($frame->calledArgs[2])) {
            $tags = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $tags->type && Variable::TYPE_ARRAY !== $tags->type) {
                throw new \TypeError(\sprintf(
                    'MongoDB\\Driver\\ReadPreference::__construct(): Argument #2 ($tagSets) must be of type ?array, %s given',
                    VmMongodb::typeLabel($tags)
                ));
            }
        }
        if (isset($frame->calledArgs[3])) {
            $opt = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $opt->type && Variable::TYPE_ARRAY !== $opt->type) {
                throw new \TypeError(\sprintf(
                    'MongoDB\\Driver\\ReadPreference::__construct(): Argument #3 ($options) must be of type ?array, %s given',
                    VmMongodb::typeLabel($opt)
                ));
            }
        }
        VmMongodbTypes::initReadPreference($var->toObject(), $mode, $modeString);
    }
}

final class ReadPreferenceGetMode extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getMode');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\Driver\\ReadPreference::getMode', VmMongodbTypes::READ_PREFERENCE_LC);
        $frame->returnVar->int(VmMongodbTypes::readPreferenceState($object)['mode']);
    }
}

final class ReadPreferenceGetModeString extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getModeString');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\Driver\\ReadPreference::getModeString', VmMongodbTypes::READ_PREFERENCE_LC);
        $frame->returnVar->string(VmMongodbTypes::readPreferenceState($object)['modeString']);
    }
}

final class WriteConcernConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('MongoDB\\Driver\\WriteConcern::__construct() expects at least 1 argument');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\Driver\\WriteConcern::__construct() must be called on WriteConcern');
        }
        $w = VmMongodbTypes::resolveWriteConcernW($frame->calledArgs[1]);
        $wtimeout = 0;
        if (isset($frame->calledArgs[2])) {
            $wt = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $wt->type) {
                if (Variable::TYPE_INTEGER !== $wt->type) {
                    throw new \TypeError(\sprintf(
                        'MongoDB\\Driver\\WriteConcern::__construct(): Argument #2 ($wtimeout) must be of type ?int, %s given',
                        VmMongodb::typeLabel($wt)
                    ));
                }
                $wtimeout = $wt->toInt();
            }
        }
        $journal = null;
        if (isset($frame->calledArgs[3])) {
            $j = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $j->type) {
                if (Variable::TYPE_BOOLEAN !== $j->type) {
                    throw new \TypeError(\sprintf(
                        'MongoDB\\Driver\\WriteConcern::__construct(): Argument #3 ($journal) must be of type ?bool, %s given',
                        VmMongodb::typeLabel($j)
                    ));
                }
                $journal = $j->toBool();
            }
        }
        VmMongodbTypes::initWriteConcern($var->toObject(), $w, $wtimeout, $journal);
    }
}

final class WriteConcernGetW extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getW');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\Driver\\WriteConcern::getW', VmMongodbTypes::WRITE_CONCERN_LC);
        $w = VmMongodbTypes::writeConcernState($object)['w'];
        if (\is_string($w)) {
            $frame->returnVar->string($w);
        } else {
            $frame->returnVar->int($w);
        }
    }
}

final class WriteConcernGetWtimeout extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getWtimeout');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\Driver\\WriteConcern::getWtimeout', VmMongodbTypes::WRITE_CONCERN_LC);
        $frame->returnVar->int(VmMongodbTypes::writeConcernState($object)['wtimeout']);
    }
}

final class WriteConcernGetJournal extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getJournal');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\Driver\\WriteConcern::getJournal', VmMongodbTypes::WRITE_CONCERN_LC);
        $journal = VmMongodbTypes::writeConcernState($object)['journal'];
        if (null === $journal) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->bool($journal);
        }
    }
}
