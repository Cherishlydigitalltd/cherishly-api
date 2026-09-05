<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Jobs\SendSupportEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function submit(Request $request): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        SendSupportEmail::dispatch($request->user(), $request->message);

        return ApiResponse::success('Your request has been submitted. We\'ll get back to you within 24 hours.');
    }
}