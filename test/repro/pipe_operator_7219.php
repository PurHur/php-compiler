<?php
echo 1 |> strval, "\n";
$result = "PHP Rocks"
    |> htmlentities(...)
    |> str_split(...)
    |> (fn($x) => array_map(strtoupper(...), $x));
print_r($result);
