<?php
/**
 * Differential: nested unset mutates live array element (#36380).
 * Uses isset (not array_key_exists) — AOT ake after nested unset is a separate gap.
 */
$a = [['name' => 'p', 'handler' => 1]];
unset($a[0]['name']);
echo isset($a[0]['name']) ? 'bad' : 'ok';
echo '|';
$b = ['element' => ['attributes' => ['href' => 'x', 'alt' => 'y']]];
unset($b['element']['attributes']['href']);
echo isset($b['element']['attributes']['href']) ? 'bad' : 'ok';
echo "\n";
