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

    public function contact(Request $request): JsonResponse
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', 'string', 'max:50'],
            'question' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        \App\Jobs\SendContactEmail::dispatch($request->only([
            'first_name',
            'last_name',
            'email',
            'phone',
            'source',
            'question'
        ]));

        return ApiResponse::success('Message sent. We\'ll get back to you soon.');
    }
}