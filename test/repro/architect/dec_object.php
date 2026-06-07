<?php
$o = new stdClass();
try {
    --$o;
} catch (TypeError $e) {
    echo 'TypeError: '.$e->getMessage()."\n";
}
