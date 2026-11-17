<?php

use App\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt'  => \App\Http\Middleware\JwtAuthenticate::class,
            'role' => \App\Http\Middleware\RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));

        $exceptions->render(function (BusinessException $e, Request $request) {
            return response()->json(['error' => [
                'code'    => $e->errorCode,
                'message' => $e->getMessage(),
                'details' => $e->details ?: new \stdClass(),
            ]], $e->status);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json(['error' => [
                'code'    => 'VALIDATION_ERREUR',
                'message' => 'Donnees invalides.',
                'details' => $e->errors(),
            ]], 422);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            return response()->json(['error' => [
                'code'    => 'RESSOURCE_INTROUVABLE',
                'message' => 'Ressource introuvable.',
                'details' => new \stdClass(),
            ]], 404);
        });
    })->create();
