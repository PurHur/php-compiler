<?php
/**
 * Differential: nested unset mutates live array element (#36380).
 * array_key_exists after unset covered by array_key_exists_after_unset_36732.php (#36732).
 */
$a = [['name' => 'p', 'handler' => 1]];
unset($a[0]['name']);
echo isset($a[0]['name']) ? 'bad' : 'ok';
echo '|';
$b = ['element' => ['attributes' => ['href' => 'x', 'alt' => 'y']]];
unset($b['element']['attributes']['href']);
echo isset($b['element']['attributes']['href']) ? 'bad' : 'ok';
echo "\n";
