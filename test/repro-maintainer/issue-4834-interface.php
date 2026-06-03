<?php
interface HasTitle {
    public string $title {
        get;
    }
}
class Page implements HasTitle {
    public string $title = 'home';
}
echo (new Page())->title, "\n";
