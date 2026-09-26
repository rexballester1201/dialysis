<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Controllers stay thin: FormRequest -> Policy -> Service -> API Resource.
    use AuthorizesRequests;
}
