<?php function s(...$v){ echo implode(",", $v), "\n"; } $p=[1,2,3]; s(...$p); s(0, ...$p);
