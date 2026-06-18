<?php
declare(strict_types=1);

function maint_static_postinc(): int {
    static $n = 10;
    return $n++;
}
var_dump(maint_static_postinc());
var_dump(maint_static_postinc());

$c = function (): int {
    static $n = 10;
    return $n++;
};
var_dump($c());
var_dump($c());
