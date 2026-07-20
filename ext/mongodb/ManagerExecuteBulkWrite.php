<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** MongoDB\Driver\Manager::executeBulkWrite() — deferred wire protocol (#6575). */
final class ManagerExecuteBulkWrite extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('executeBulkWrite');
    }

    public function execute(Frame $frame): void
    {
        $this->receiver($frame, 'MongoDB\\Driver\\Manager::executeBulkWrite', VmMongodb::MANAGER_LC);
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'MongoDB\\Driver\\Manager::executeBulkWrite() expects at least 2 arguments'
            );
        }
        $this->stringArg($frame->calledArgs[1], 'MongoDB\\Driver\\Manager::executeBulkWrite', 1, 'namespace');
        $bulk = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $bulk->type
            || VmMongodb::BULKWRITE_LC !== strtolower($bulk->toObject()->class->name)) {
            throw new \TypeError(
                'MongoDB\\Driver\\Manager::executeBulkWrite(): Argument #2 ($bulk) must be of type MongoDB\\Driver\\BulkWrite'
            );
        }

        throw new \RuntimeException(
            'MongoDB\\Driver\\Manager::executeBulkWrite(): native MongoDB wire protocol not linked in this build (#6575)'
        );
    }
}
