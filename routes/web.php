<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The SPA owns every non-API path. This single catch-all serves the React
| shell so client-side routing (/admin-users, /my-ipcr, …) survives a hard
| refresh and deep links work.
|
*/

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
