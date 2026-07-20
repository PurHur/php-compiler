<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** MongoDB\Driver\Query::__construct (#6575). */
final class QueryConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('MongoDB\\Driver\\Query::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\Driver\\Query::__construct() must be called on Query');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('MongoDB\\Driver\\Query::__construct() expects at least 1 argument');
        }
        $filter = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $filter->type && Variable::TYPE_OBJECT !== $filter->type) {
            throw new \TypeError(\sprintf(
                'MongoDB\\Driver\\Query::__construct(): Argument #1 ($filter) must be of type array|object, %s given',
                VmMongodb::typeLabel($filter)
            ));
        }
        if (isset($frame->calledArgs[2])) {
            $opt = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $opt->type && Variable::TYPE_ARRAY !== $opt->type) {
                throw new \TypeError(\sprintf(
                    'MongoDB\\Driver\\Query::__construct(): Argument #2 ($queryOptions) must be of type ?array, %s given',
                    VmMongodb::typeLabel($opt)
                ));
            }
        }
        VmMongodb::initSimple($var->toObject());
    }
}
