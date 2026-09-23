<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (PostTooLargeException $e, Request $request) {
            if ($request->is('api/*')) {
                $limit = ini_get('upload_max_filesize');

                return response()->json([
                    'message' => "That file is too large for this server to accept (its limit is {$limit}). Attach a file of 5 MB or less.",
                ], 413);
            }
        });
    }

    protected function shouldReturnJson($request, Throwable $e)
    {
        return $request->is('api/*') || parent::shouldReturnJson($request, $e);
    }

    protected function invalid($request, \Illuminate\Validation\ValidationException $exception)
    {
        if ($request->is('api/*')) {
            return $this->invalidJson($request, $exception);
        }

        return parent::invalid($request, $exception);
    }
}
