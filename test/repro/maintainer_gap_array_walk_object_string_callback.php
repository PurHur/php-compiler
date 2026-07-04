<?php

declare(strict_types=1);

$a = (object) ['x' => 1];
array_walk($a, 'var_dump');
