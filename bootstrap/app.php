<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(except: ['soap/sipus']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'type' => url('/docs/errors/unauthenticated'),
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => 'Sertakan bearer token yang valid pada header Authorization.',
                'instance' => $request->getRequestUri(),
            ], 401)->header('Content-Type', 'application/problem+json');
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'type' => url('/docs/errors/not-found'),
                'title' => 'Not Found',
                'status' => 404,
                'detail' => 'Endpoint atau resource yang diminta tidak ditemukan.',
                'instance' => $request->getRequestUri(),
            ], 404)->header('Content-Type', 'application/problem+json');
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'type' => url('/docs/errors/validation-failed'),
                'title' => 'Unprocessable Content',
                'status' => 422,
                'detail' => 'Satu atau lebih atribut input tidak memenuhi aturan validasi.',
                'instance' => $request->getRequestUri(),
                'errors' => $exception->errors(),
            ], 422)->header('Content-Type', 'application/problem+json');
        });
    })->create();
