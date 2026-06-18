<?php
declare(strict_types=1);

// Issue #9374: match() as nested call argument must evaluate correctly.
var_dump(strlen(match (1) { 1 => 'abc' }));
var_dump(match (1) { 1 => 'a', default => 'd' });
var_dump(count(match ([1]) { [1] => [1, 2] }));
