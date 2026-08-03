<?php
// #27133 — AOT array_search on packed list must return key (not NULL)
var_export(array_search('b', ['a', 'b', 'c']));
echo "\n";
