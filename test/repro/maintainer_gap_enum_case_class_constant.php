<?php
enum E: string { case A = 'a'; }
var_dump(E::A::class);
