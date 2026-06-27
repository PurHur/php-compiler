<?php

declare(strict_types=1);

$a = ['IMG12', 'img2', 'Img1'];
natcasesort($a);
echo implode(',', array_values($a));
