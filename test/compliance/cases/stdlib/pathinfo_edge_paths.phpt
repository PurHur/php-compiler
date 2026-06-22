--TEST--
stdlib pathinfo() empty path and dot-path edge cases (#10277)
--FILE--
<?php
var_export(pathinfo('', PATHINFO_DIRNAME));
echo "\n";
var_export(pathinfo('.', PATHINFO_FILENAME));
echo "\n";
var_export(array_keys(pathinfo('')));
echo "\n";
var_export(pathinfo('.'));
echo "\n";
var_export(pathinfo('.cvsignore'));
echo "\n";
var_export(pathinfo('/path/noextension'));
echo "\n";
var_export(pathinfo('/path/emptyextension.'));
echo "\n";
--EXPECT--
''
''
array (
  0 => 'basename',
  1 => 'filename',
)
array (
  'dirname' => '.',
  'basename' => '.',
  'extension' => '',
  'filename' => '',
)
array (
  'dirname' => '.',
  'basename' => '.cvsignore',
  'extension' => 'cvsignore',
  'filename' => '',
)
array (
  'dirname' => '/path',
  'basename' => 'noextension',
  'filename' => 'noextension',
)
array (
  'dirname' => '/path',
  'basename' => 'emptyextension.',
  'extension' => '',
  'filename' => 'emptyextension',
)
