<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// API（/api）・CSRF クッキー（/sanctum）・死活監視（/up）以外の GET はすべて SPA に任せる
Route::get('/{path?}', SpaController::class)->where('path', '^(?!api/|sanctum/|up$).*');
