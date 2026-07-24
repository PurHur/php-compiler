<?php
declare(strict_types=1);

echo 'loaded=', extension_loaded('gd') ? 'yes' : 'no', "\n";
echo 'gd_info=', function_exists('gd_info') ? 'yes' : 'no', "\n";
echo 'imagecreate=', function_exists('imagecreate') ? 'yes' : 'no', "\n";
echo 'in_list=', in_array('gd', get_loaded_extensions(), true) ? 'yes' : 'no', "\n";
echo 'funcs=', false !== get_extension_funcs('gd') ? 'yes' : 'no', "\n";
