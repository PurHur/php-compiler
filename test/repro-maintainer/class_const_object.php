<?php

class Box {
    public const O = new stdClass();
}
$a = Box::O;
$b = Box::O;
echo ($a === $b) ? "same\n" : "diff\n";
