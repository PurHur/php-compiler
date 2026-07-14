<?php

try {
    var_export(unserialize(null));
} catch (TypeError $e) {
    echo 'TypeError: '.$e->getMessage();
}
