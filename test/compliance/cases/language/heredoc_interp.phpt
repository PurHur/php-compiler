--TEST--
Language: heredoc with {$var} and $var interpolation (issue #178)
--FILE--
<?php
$name = 'world';
echo <<<HTML
<div>{$name}</div>
<p>$name</p>
HTML;
--EXPECT--
<div>world</div>
<p>world</p>
