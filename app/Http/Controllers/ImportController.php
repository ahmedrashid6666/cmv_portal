<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class ImportController extends Controller
{
    public function index()
    {
        return Inertia::render('Import/Index');
    }
}
