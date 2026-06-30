<?php

declare(strict_types=1);

parse_str('a.b=1&a.c=2', $dots);
parse_str('a+b=1', $plus);
echo 'dots:'.json_encode($dots)."\n";
echo 'plus:'.json_encode($plus)."\n";
