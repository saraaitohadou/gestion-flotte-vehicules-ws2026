<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/docs');
Route::view('/docs', 'docs');   // Swagger UI -> /openapi.yaml
