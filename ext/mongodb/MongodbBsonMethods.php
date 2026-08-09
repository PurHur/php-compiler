<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** MongoDB\BSON\* method handlers (#27875). */
final class ObjectIdConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('MongoDB\\BSON\\ObjectId::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\BSON\\ObjectId::__construct() must be called on ObjectId');
        }
        $id = null;
        if (isset($frame->calledArgs[1])) {
            $id = VmMongodbTypes::coerceOptionalObjectId(
                $frame->calledArgs[1],
                'MongoDB\\BSON\\ObjectId::__construct'
            );
        }
        VmMongodbTypes::initObjectId($var->toObject(), $id);
    }
}

final class ObjectIdToString extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\ObjectId::__toString', VmMongodbTypes::OBJECT_ID_LC);
        $frame->returnVar->string(VmMongodbTypes::objectIdHex($object));
    }
}

final class ObjectIdGetTimestamp extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getTimestamp');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\ObjectId::getTimestamp', VmMongodbTypes::OBJECT_ID_LC);
        $frame->returnVar->int(VmMongodbTypes::objectIdTimestamp($object));
    }
}

final class UtcDateTimeConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('MongoDB\\BSON\\UTCDateTime::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\BSON\\UTCDateTime::__construct() must be called on UTCDateTime');
        }
        $ms = VmMongodbTypes::utcMillisecondsFromArg($frame->calledArgs[1] ?? null);
        VmMongodbTypes::initUtcDateTime($var->toObject(), $ms);
    }
}

final class UtcDateTimeToString extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\UTCDateTime::__toString', VmMongodbTypes::UTC_DATE_TIME_LC);
        $frame->returnVar->string(VmMongodbTypes::utcDateTimeMs($object));
    }
}

final class BinaryConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('MongoDB\\BSON\\Binary::__construct() expects at least 1 argument');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\BSON\\Binary::__construct() must be called on Binary');
        }
        $data = $this->stringArg($frame->calledArgs[1], 'MongoDB\\BSON\\Binary::__construct', 0, 'data');
        $type = 0;
        if (isset($frame->calledArgs[2])) {
            $typeArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $typeArg->type) {
                throw new \TypeError(\sprintf(
                    'MongoDB\\BSON\\Binary::__construct(): Argument #2 ($type) must be of type int, %s given',
                    VmMongodb::typeLabel($typeArg)
                ));
            }
            $type = $typeArg->toInt();
        }
        VmMongodbTypes::initBinary($var->toObject(), $data, $type);
    }
}

final class BinaryGetData extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getData');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\Binary::getData', VmMongodbTypes::BINARY_LC);
        $frame->returnVar->string(VmMongodbTypes::binaryState($object)['data']);
    }
}

final class BinaryGetType extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getType');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\Binary::getType', VmMongodbTypes::BINARY_LC);
        $frame->returnVar->int(VmMongodbTypes::binaryState($object)['type']);
    }
}

final class RegexConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('MongoDB\\BSON\\Regex::__construct() expects at least 1 argument');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\BSON\\Regex::__construct() must be called on Regex');
        }
        $pattern = $this->stringArg($frame->calledArgs[1], 'MongoDB\\BSON\\Regex::__construct', 0, 'pattern');
        $flags = '';
        if (isset($frame->calledArgs[2])) {
            $flags = $this->stringArg($frame->calledArgs[2], 'MongoDB\\BSON\\Regex::__construct', 1, 'flags');
        }
        VmMongodbTypes::initRegex($var->toObject(), $pattern, $flags);
    }
}

final class RegexGetPattern extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getPattern');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\Regex::getPattern', VmMongodbTypes::REGEX_LC);
        $frame->returnVar->string(VmMongodbTypes::regexState($object)['pattern']);
    }
}

final class RegexGetFlags extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getFlags');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\Regex::getFlags', VmMongodbTypes::REGEX_LC);
        $frame->returnVar->string(VmMongodbTypes::regexState($object)['flags']);
    }
}

final class RegexToString extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\Regex::__toString', VmMongodbTypes::REGEX_LC);
        $state = VmMongodbTypes::regexState($object);
        $frame->returnVar->string('/'.$state['pattern'].'/'.$state['flags']);
    }
}

final class Decimal128Construct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('MongoDB\\BSON\\Decimal128::__construct() expects exactly 1 argument');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\BSON\\Decimal128::__construct() must be called on Decimal128');
        }
        $value = $this->stringArg($frame->calledArgs[1], 'MongoDB\\BSON\\Decimal128::__construct', 0, 'value');
        VmMongodbTypes::initDecimal128($var->toObject(), $value);
    }
}

final class Decimal128ToString extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\Decimal128::__toString', VmMongodbTypes::DECIMAL128_LC);
        $frame->returnVar->string(VmMongodbTypes::decimal128Value($object));
    }
}

final class TimestampConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('MongoDB\\BSON\\Timestamp::__construct() expects exactly 2 arguments');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\BSON\\Timestamp::__construct() must be called on Timestamp');
        }
        $inc = $frame->calledArgs[1]->resolveIndirect();
        $ts = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $inc->type) {
            throw new \TypeError(\sprintf(
                'MongoDB\\BSON\\Timestamp::__construct(): Argument #1 ($increment) must be of type int, %s given',
                VmMongodb::typeLabel($inc)
            ));
        }
        if (Variable::TYPE_INTEGER !== $ts->type) {
            throw new \TypeError(\sprintf(
                'MongoDB\\BSON\\Timestamp::__construct(): Argument #2 ($timestamp) must be of type int, %s given',
                VmMongodb::typeLabel($ts)
            ));
        }
        VmMongodbTypes::initTimestamp($var->toObject(), $inc->toInt(), $ts->toInt());
    }
}

final class TimestampGetIncrement extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getIncrement');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\Timestamp::getIncrement', VmMongodbTypes::TIMESTAMP_LC);
        $frame->returnVar->int(VmMongodbTypes::timestampState($object)['increment']);
    }
}

final class TimestampGetTimestamp extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('getTimestamp');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\Timestamp::getTimestamp', VmMongodbTypes::TIMESTAMP_LC);
        $frame->returnVar->int(VmMongodbTypes::timestampState($object)['timestamp']);
    }
}

final class TimestampToString extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = $this->receiver($frame, 'MongoDB\\BSON\\Timestamp::__toString', VmMongodbTypes::TIMESTAMP_LC);
        $state = VmMongodbTypes::timestampState($object);
        $frame->returnVar->string(\sprintf('[%d:%d]', $state['increment'], $state['timestamp']));
    }
}
