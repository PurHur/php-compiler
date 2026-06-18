<?php
$src = '<?php echo 1;';
echo "default:\n";
$tokens = token_get_all($src);
echo count($tokens), "\n";
foreach ($tokens as $i => $t) {
    if (is_array($t)) {
        echo "$i:", token_name($t[0]), "\n";
    } else {
        echo "$i:lit\n";
    }
}
echo "TOKEN_PARSE:\n";
$tokens = token_get_all($src, TOKEN_PARSE);
echo count($tokens), "\n";
foreach ($tokens as $i => $t) {
    if (is_array($t)) {
        echo "$i:", token_name($t[0]), "\n";
    } else {
        echo "$i:lit\n";
    }
}
