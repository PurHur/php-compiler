<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** MongoDB\Driver\Manager::executeQuery() — deferred wire protocol (#6575). */
final class ManagerExecuteQuery extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('executeQuery');
    }

    public function execute(Frame $frame): void
    {
        $this->receiver($frame, 'MongoDB\\Driver\\Manager::executeQuery', VmMongodb::MANAGER_LC);
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'MongoDB\\Driver\\Manager::executeQuery() expects at least 2 arguments'
            );
        }
        $this->stringArg($frame->calledArgs[1], 'MongoDB\\Driver\\Manager::executeQuery', 1, 'namespace');
        $query = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $query->type
            || VmMongodb::QUERY_LC !== strtolower($query->toObject()->class->name)) {
            throw new \TypeError(
                'MongoDB\\Driver\\Manager::executeQuery(): Argument #2 ($query) must be of type MongoDB\\Driver\\Query'
            );
        }

        throw new \RuntimeException(
            'MongoDB\\Driver\\Manager::executeQuery(): native MongoDB wire protocol not linked in this build (#6575)'
        );
    }
}
