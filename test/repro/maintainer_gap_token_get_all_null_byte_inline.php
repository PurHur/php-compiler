<?php
declare(strict_types=1);

$source = "<? \0";
$tokens = token_get_all($source);
echo count($tokens), "\n";
foreach ($tokens as $token) {
    if (\is_array($token)) {
        echo token_name($token[0]), ' ', json_encode($token[1]), "\n";
    } else {
        echo $token, "\n";
    }
}
