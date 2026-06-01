<?php

echo nl2br("a\r\nb"), "\n";
echo nl2br("a\rb"), "\n";
echo nl2br("a\n\rb"), "\n";
echo nl2br("x", false), "\n";
