<?php
// @differential-repeat: 30   heap corruption on AOT spread→variadic→implode was intermittent (#24226)
function s(...$v){ echo implode(",", $v), "\n"; } $p=[1,2,3]; s(...$p); s(0, ...$p);
