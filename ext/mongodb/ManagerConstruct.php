<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** MongoDB\Driver\Manager::__construct (#6575). */
final class ManagerConstruct extends MongodbClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('MongoDB\\Driver\\Manager::__construct() called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError('MongoDB\\Driver\\Manager::__construct() must be called on Manager');
        }
        $object = $var->toObject();

        $uri = 'mongodb://127.0.0.1/';
        if (isset($frame->calledArgs[1])) {
            $uriArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $uriArg->type) {
                $uri = VmMongodb::coerceUri($uriArg, 'MongoDB\\Driver\\Manager::__construct');
            }
        }

        $uriOptions = null;
        if (isset($frame->calledArgs[2])) {
            $opt = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $opt->type) {
                if (Variable::TYPE_ARRAY !== $opt->type) {
                    throw new \TypeError(\sprintf(
                        'MongoDB\\Driver\\Manager::__construct(): Argument #2 ($uriOptions) must be of type ?array, %s given',
                        VmMongodb::typeLabel($opt)
                    ));
                }
                $uriOptions = [];
            }
        }

        $driverOptions = null;
        if (isset($frame->calledArgs[3])) {
            $opt = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $opt->type) {
                if (Variable::TYPE_ARRAY !== $opt->type) {
                    throw new \TypeError(\sprintf(
                        'MongoDB\\Driver\\Manager::__construct(): Argument #3 ($driverOptions) must be of type ?array, %s given',
                        VmMongodb::typeLabel($opt)
                    ));
                }
                $driverOptions = [];
            }
        }

        VmMongodb::initManager($object, $uri, $uriOptions, $driverOptions);
    }
}
