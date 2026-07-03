<?php

declare(strict_types=1);

var_dump(enum_exists('NotYet'));
enum NotYet { case A; }
var_dump(enum_exists('NotYet'));
var_dump(enum_exists('NotYet', false));
