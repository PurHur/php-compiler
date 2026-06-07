<?php
foreach (['getmyuid', 'getmygid', 'get_current_user', 'get_cfg_var'] as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'NO', "\n";
}
echo 'getmyuid(): ', getmyuid(), "\n";
echo 'getmygid(): ', getmygid(), "\n";
echo 'get_current_user(): ', get_current_user(), "\n";
echo 'get_cfg_var(memory_limit): ', get_cfg_var('memory_limit'), "\n";
