<?php
$c = function () {
    return 1;
};
$c->bindTo(new stdClass(), 'MissingScopeClass');
