--TEST--
AOT: heredoc with {$var} interpolation (issue #3187)
--FILE--
<?php
$name = 'world';
echo <<<HTML
<div>{$name}</div>
HTML;
--EXPECT--
<div>world</div>
