<?php
foreach (['str_replace' => static fn () => str_replace('a', 'b', null),
           'str_ireplace' => static fn () => str_ireplace('a', 'b', null),
           'preg_replace' => static fn () => preg_replace('//', 'x', null)] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
