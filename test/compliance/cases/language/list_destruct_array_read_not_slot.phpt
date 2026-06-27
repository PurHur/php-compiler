--TEST--
Language: string-key array read is not list destructuring (#12602)
--FILE--
<?php
$options = ['-y' => '/tmp/out', '-o' => 'build'];
$debugFile = true === $options['-y'] ? $options['-o'] : $options['-y'];
echo $debugFile, "\n";
$x = $options['-o'];
echo $x, "\n";
--EXPECT--
/tmp/out
build
