<?php
declare(strict_types=1);

namespace Dtrms\Controllers;

use Dtrms\Core\View;
use Dtrms\Services\BrowserSession;

final class HomeController
{
    public function __construct(private BrowserSession $browser)
    {
    }

    public function index(array $principal): string
    {
        return View::render('home', [
            'title' => 'System overview',
            'principal' => $principal,
            'csrf' => $this->browser->csrf(),
        ]);
    }
}
