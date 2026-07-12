<?php
// get_class_methods() on unknown class must TypeError (#18110, ext/standard/basic_functions.c)
try {
    get_class_methods('NoSuch');
    echo "fail: expected TypeError\n";
} catch (TypeError $e) {
    echo 'ok: ', $e->getMessage(), "\n";
}
