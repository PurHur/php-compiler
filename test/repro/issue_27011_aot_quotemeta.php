<?php

echo quotemeta('a.b'), "\n";
echo quotemeta('$x+y'), "\n";
echo quotemeta('plain'), "\n";
echo quotemeta('.\\+*?[]^()$'), "\n";
