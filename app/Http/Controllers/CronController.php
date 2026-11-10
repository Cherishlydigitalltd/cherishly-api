<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Stand-in for a server cron job. The host can't run cron, so an outside
 * service (e.g. cron-job.org) calls this URL every minute:
 *
 *   GET /api/cron/run?key=<CRON_SECRET>
 *
 * It runs the scheduler (scheduled invitations, settlements, marketplace
 * auto-complete) and then works through any queued jobs (emails, SMS).
 * With no CRON_SECRET set, the URL behaves as if it doesn't exist.
 */
class CronController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        $secret = (string) config('services.cron.secret');

        if ($secret === '' || ! hash_equals($secret, (string) $request->query('key'))) {
            abort(404);
        }

        // Two pings overlapping (a slow run plus the next minute's call)
        // would only queue up behind each other, so skip instead.
        $lock = Cache::lock('cron-run', 120);
        if (! $lock->get()) {
            return response()->json(['status' => true, 'message' => 'Already running.']);
        }

        @set_time_limit(90);
        $started = microtime(true);

        try {
            Artisan::call('schedule:run');

            // Only does anything when QUEUE_CONNECTION=database; with "sync"
            // jobs already ran when they were dispatched.
            if (config('queue.default') !== 'sync') {
                Artisan::call('queue:work', [
                    '--stop-when-empty' => true,
                    '--max-time' => 45,
                    '--tries' => 3,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Cron run failed: ' . $e->getMessage());
            report($e); // so it reaches the error-alert email

            return response()->json(['status' => false, 'message' => 'Cron run failed.'], 500);
        } finally {
            $lock->release();
        }

        return response()->json([
            'status' => true,
            'message' => 'OK',
            'seconds' => round(microtime(true) - $started, 2),
        ]);
    }

    /**
     * GET /api/cron/test-alert?key=<CRON_SECRET>
     * Sends a test error alert, to check ALERT_EMAIL works.
     */
    public function testAlert(Request $request): JsonResponse
    {
        $secret = (string) config('services.cron.secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->query('key'))) {
            abort(404);
        }
        if (! config('app.alert_email')) {
            return response()->json(['status' => false, 'message' => 'ALERT_EMAIL is not set.'], 422);
        }

        // Clear the quiet period afterwards so the test can be repeated straight away.
        \App\Support\ErrorAlert::report(new \RuntimeException('Test alert ' . now()->format('H:i:s') . ': if you can read this, error alerts work.'));
        \Illuminate\Support\Facades\Cache::forget('error-alert:' . md5(\RuntimeException::class . '|' . __FILE__ . '|' . (__LINE__ - 1)));

        return response()->json(['status' => true, 'message' => 'Test alert sent to ' . config('app.alert_email') . '.']);
    }
}
