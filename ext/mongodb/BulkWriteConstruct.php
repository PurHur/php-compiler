<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** MongoDB\Driver\BulkWrite::__construct (#6575). */
final class BulkWriteConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('MongoDB\\Driver\\BulkWrite::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\Driver\\BulkWrite::__construct() must be called on BulkWrite');
        }
        if (isset($frame->calledArgs[1])) {
            $opt = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $opt->type && Variable::TYPE_ARRAY !== $opt->type) {
                throw new \TypeError(\sprintf(
                    'MongoDB\\Driver\\BulkWrite::__construct(): Argument #1 ($options) must be of type ?array, %s given',
                    VmMongodb::typeLabel($opt)
                ));
            }
        }
        VmMongodb::initSimple($var->toObject());
    }
}
