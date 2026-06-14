<?php
echo function_exists('mb_str_pad') ? "exists\n" : "missing\n";
echo mb_str_pad('hi', 5, ' ', STR_PAD_BOTH), "\n";
echo mb_str_pad('日', 4, '本'), "\n";
