<?php
echo 'fn=', (int) function_exists('tidy_parse_string'), "\n";
echo 'class=', (int) class_exists('tidy'), "\n";
echo 'ext=', (int) extension_loaded('tidy'), "\n";
