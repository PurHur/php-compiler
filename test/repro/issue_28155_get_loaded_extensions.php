<?php

$e = get_loaded_extensions();
sort($e);
echo 'Core=', in_array('Core', $e, true) ? 'y' : 'n', "\n";
echo 'core=', in_array('core', $e, true) ? 'y' : 'n', "\n";
echo 'SPL=', in_array('SPL', $e, true) ? 'y' : 'n', "\n";
echo 'PDO=', in_array('PDO', $e, true) ? 'y' : 'n', "\n";
echo 'Phar=', in_array('Phar', $e, true) ? 'y' : 'n', "\n";
echo 'Reflection=', in_array('Reflection', $e, true) ? 'y' : 'n', "\n";
echo 'SimpleXML=', in_array('SimpleXML', $e, true) ? 'y' : 'n', "\n";
echo 'FFI=', in_array('FFI', $e, true) ? 'y' : 'n', "\n";
echo 'Zend OPcache=', in_array('Zend OPcache', $e, true) ? 'y' : 'n', "\n";
echo 'types=', in_array('types', $e, true) ? 'y' : 'n', "\n";
echo 'uploadprogress=', in_array('uploadprogress', $e, true) ? 'y' : 'n', "\n";
echo 'extension_loaded_types=', extension_loaded('types') ? 'y' : 'n', "\n";
