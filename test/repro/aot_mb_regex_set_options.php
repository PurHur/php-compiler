<?php
echo mb_regex_set_options(), "\n";
$prev = mb_regex_set_options('i');
echo $prev, "\n";
echo mb_regex_set_options(), "\n";
echo mb_ereg('A', 'a') ? 'y' : 'n', "\n";
