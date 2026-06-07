<?php

interface I {}
var_dump(class_implements(I::class));

interface J extends I {}
var_dump(class_implements(J::class));
