<?php

function show(string $name, $value): void
{
    if (false === $value) {
        echo $name, "=false\n";
    } elseif ('' === $value) {
        echo $name, "=empty\n";
    } else {
        echo $name, "=other:", $value, "\n";
    }
}

show('display_errors', ini_get('display_errors'));
show('short_open_tag', ini_get('short_open_tag'));
show('register_argc_argv', ini_get('register_argc_argv'));
show('zend.enable_gc', ini_get('zend.enable_gc'));

$old = ini_set('display_errors', '0');
show('after_set_off', ini_get('display_errors'));
ini_set('display_errors', '1');
show('after_set_on', ini_get('display_errors'));
ini_set('display_errors', $old);
show('after_restore', ini_get('display_errors'));
