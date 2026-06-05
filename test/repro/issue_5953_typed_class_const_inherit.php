<?php
class Base { public const string FOO = 'a'; }
class Bad extends Base { public const int FOO = 1; }
echo "compiled\n";
