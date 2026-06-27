<?php

declare(strict_types=1);

var_export(iterator_to_array(new LimitIterator(new ArrayIterator([1, 2, 3]), 1, 1)));
