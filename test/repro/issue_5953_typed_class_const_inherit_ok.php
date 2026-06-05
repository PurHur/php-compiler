<?php
class Base { public const string FOO = 'a'; }
class Child extends Base { public const string FOO = 'b'; }
echo Child::FOO, "\n";
