<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function send(string $phone, string $message): bool
    {
        // Try provider 1 first
        $sent = $this->sendViaProvider(setting('sms_provider_1', 'termii'), $phone, $message);

        // Fallback to provider 2
        if (!$sent) {
            $sent = $this->sendViaProvider(setting('sms_provider_2', 'infobip'), $phone, $message);
        }

        return $sent;
    }

    private function sendViaProvider(string $provider, string $phone, string $message): bool
    {
        try {
            return match ($provider) {
                'termii'  => $this->sendViaTermii($phone, $message),
                'infobip' => $this->sendViaInfobip($phone, $message),
                default   => false,
            };
        } catch (\Exception $e) {
            Log::error("SMS send failed via {$provider}: " . $e->getMessage());
            return false;
        }
    }

    private function sendViaTermii(string $phone, string $message): bool
    {
        $response = Http::post('https://api.ng.termii.com/api/sms/send', [
            'to'      => $phone,
            'from'    => config('services.termii.sender_id', 'Cherishly'),
            'sms'     => $message,
            'type'    => 'plain',
            'api_key' => config('services.termii.api_key'),
            'channel' => 'generic',
        ]);

        return $response->successful();
    }

    private function sendViaInfobip(string $phone, string $message): bool
    {
        $response = Http::withHeaders([
            'Authorization' => 'App ' . config('services.infobip.api_key'),
            'Content-Type'  => 'application/json',
        ])->post(config('services.infobip.base_url') . '/sms/2/text/advanced', [
            'messages' => [[
                'from'         => 'Cherishly',
                'destinations' => [['to' => $phone]],
                'text'         => $message,
            ]],
        ]);

        return $response->successful();
    }
}