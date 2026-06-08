<?php

enum Color: string {
    case Red = 'red';
    case Blue = 'blue';
}

$methods = get_class_methods(Color::Red);
sort($methods);
echo count($methods), "\n";
echo implode(',', $methods), "\n";
