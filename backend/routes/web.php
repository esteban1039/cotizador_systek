<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'application' => 'JARVIS Cotizador Systek',
    'stage' => 'Núcleo comercial — desarrollo local',
    'api' => '/api/v1',
    'health' => '/up',
]));
