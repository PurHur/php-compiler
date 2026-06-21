--TEST--
stdlib levenshtein() — named insertion_cost/replacement_cost/deletion_cost (#9983, ext/standard/string.c)
--FILE--
<?php
var_export(levenshtein('kitten', 'sitting', insertion_cost: 1, replacement_cost: 1, deletion_cost: 1));
--EXPECT--
3
