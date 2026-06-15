<?php
$gen = (function () {
    yield 1;
    yield from (function () {
        yield 2;
    })();
})();
var_export(iterator_to_array($gen));
