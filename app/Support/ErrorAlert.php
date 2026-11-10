<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails ALERT_EMAIL when the API hits an unexpected server error.
 *
 * Only real errors reach here: Laravel already skips validation errors,
 * 404s, failed logins, etc. The same error (same class, file and line) is
 * emailed at most once per 15 minutes, so an outage doesn't flood the inbox.
 * Request bodies are never included (they can hold passwords, BVNs, cards).
 */
class ErrorAlert
{
    public const QUIET_MINUTES = 15;

    public static function report(Throwable $e): void
    {
        $to = config('app.alert_email');
        if (!$to) {
            return;
        }

        try {
            $fingerprint = 'error-alert:' . md5(get_class($e) . '|' . $e->getFile() . '|' . $e->getLine());

            // add() only succeeds if the key isn't there yet.
            if (!Cache::add($fingerprint, 1, now()->addMinutes(self::QUIET_MINUTES))) {
                return;
            }

            $body = self::body($e);
            $subject = '[Cherishly API] ' . class_basename($e) . ': ' . mb_strimwidth($e->getMessage() ?: 'no message', 0, 90, '…');

            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (Throwable) {
            // Never let a failing alert (mail server down, cache down) break
            // the request or loop back into the exception handler.
        }
    }

    private static function body(Throwable $e): string
    {
        // A web request has a matched route; jobs and scheduled tasks don't.
        $request = app()->bound('request') && request()->route() ? request() : null;
        $user = $request ? ($request->user() ?? $request->user('sanctum')) : null;

        $lines = [
            'The Cherishly API hit an error.',
            '',
            'Time:     ' . now()->toDayDateTimeString() . ' (UTC)',
            'Error:    ' . get_class($e),
            'Message:  ' . $e->getMessage(),
            'Where:    ' . self::shortPath($e->getFile()) . ':' . $e->getLine(),
        ];

        for ($cause = $e->getPrevious(), $n = 0; $cause && $n < 3; $cause = $cause->getPrevious(), $n++) {
            $lines[] = 'Caused by: ' . get_class($cause) . ': ' . $cause->getMessage();
        }

        if ($request) {
            $lines[] = 'Request:  ' . $request->method() . ' ' . $request->path();
            $lines[] = 'IP:       ' . $request->ip();
            $lines[] = 'Signed in: ' . ($user ? class_basename($user) . ' #' . $user->getKey() : 'no');
        } else {
            $lines[] = 'Request:  (background job or scheduled task)';
        }

        $lines[] = '';
        $lines[] = 'Where it came from:';
        foreach (array_slice($e->getTrace(), 0, 12) as $i => $frame) {
            $at = isset($frame['file']) ? self::shortPath($frame['file']) . ':' . ($frame['line'] ?? '?') : '[internal]';
            $fn = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $lines[] = "  #{$i} {$at}  {$fn}()";
        }

        $lines[] = '';
        $lines[] = 'The same error won’t be emailed again for ' . self::QUIET_MINUTES . ' minutes.';
        $lines[] = 'Full details are in storage/logs/laravel.log on the server.';

        return implode("\n", $lines);
    }

    private static function shortPath(string $path): string
    {
        return str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
    }
}
