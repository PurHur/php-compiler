<?php
function gen(): Generator {
    yield 'a' => 1;
    yield 'b' => 2;
}

var_export(iterator_to_array(gen(), true));
echo "\n";
var_export(iterator_to_array(gen(), preserve_keys: false));
echo "\n";
