<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $laravelVersion = app()->version();
    return view('welcome', ['laravelVersion' => $laravelVersion]);
});

require __DIR__.'/auth.php';
