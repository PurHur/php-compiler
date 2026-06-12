<?php
var_export(function_exists('mb_scrub'));
echo "\n";
echo mb_scrub("\xFF", 'UTF-8'), "\n";
echo mb_scrub('café', 'UTF-8'), "\n";
echo mb_scrub("\xFF"), "\n";
