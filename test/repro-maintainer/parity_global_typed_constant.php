<?php
declare(strict_types=1);

namespace TypedGlobal {
    const string NS_NAME = 'beta';
}

namespace {
    const string GLOBAL_NAME = 'alpha';
    const array GLOBAL_LIST = [1, 2, 3];

    echo GLOBAL_NAME, "\n";
    echo GLOBAL_LIST[0], "\n";
    echo \TypedGlobal\NS_NAME, "\n";
}
