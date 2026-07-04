<?php
declare(strict_types=1);

function gen(): Generator
{
    yield 'k' => 9;
}

var_export(generator_to_array(gen(), false));
echo "\n";
var_export(generator_to_array(gen(), preserve_keys: true));
echo "\n";
