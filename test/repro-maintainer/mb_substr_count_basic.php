<?php

declare(strict_types=1);

var_dump(function_exists('mb_substr_count'));
echo mb_substr_count('αβαβα', 'α'), "\n";
echo substr_count('αβαβα', 'α'), "\n";
