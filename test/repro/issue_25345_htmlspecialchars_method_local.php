<?php

declare(strict_types=1);

/**
 * Repro #25345 — AOT htmlspecialchars() on method locals / literals must keep content.
 *
 * Zend/VM: meth_app=[MiniWebApp] …; broken AOT emptied htmlspecialchars of method locals.
 */
class R
{
    private function render(): void
    {
        $appName = 'MiniWebApp';
        $title = 'Home';
        echo 'meth_app=[', htmlspecialchars($appName), ']', "\n";
        echo 'meth_title=[', htmlspecialchars($title), ']', "\n";
        echo 'lit=[', htmlspecialchars('MiniWebApp'), ']', "\n";
        echo 'esc=[', htmlspecialchars('a&b<'), ']', "\n";
    }

    public function go(): void
    {
        $this->render();
    }
}

(new R())->go();
