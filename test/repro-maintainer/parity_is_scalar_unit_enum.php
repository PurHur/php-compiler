<?php
enum U { case A; }
var_dump(is_scalar(U::A));
var_dump(is_scalar(U::cases()[0]));
