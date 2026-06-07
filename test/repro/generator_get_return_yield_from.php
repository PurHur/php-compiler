<?php
$sub = function (): Generator {
    yield 1;
    return 'inner';
};
$outer = (function () use ($sub): Generator {
    return yield from $sub();
})();
foreach ($outer as $v) {
    echo $v, "\n";
}
var_export($outer->getReturn());
echo "\n";
