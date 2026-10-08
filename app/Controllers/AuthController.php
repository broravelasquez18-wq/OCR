<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

final class AuthController extends Controller
{
    public function form(): void
    {
        $this->redirect('/lotes');
    }

    public function login(): void
    {
        $this->redirect('/lotes');
    }

    public function logout(): void
    {
        $this->redirect('/lotes');
    }
}
