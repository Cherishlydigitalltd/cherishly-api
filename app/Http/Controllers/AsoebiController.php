<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\AsoebiOrder;
use App\Models\AsoebiPackage;
use App\Models\Event;
use App\Services\AsoebiService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Aso-ebi. Buyers order through /a/<code>; helpers hand orders over through
 * /a-handout/<code>; the event's owner (the coordinator) runs it all.
 */
class AsoebiController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private AsoebiService $asoebi) {}

    /* ── Buyers ───────────────────────────────────── */

    /** GET /public/asoebi/{code} */
    public function shop(string $code): JsonResponse
    {
        return ApiResponse::success('Aso-ebi.', $this->asoebi->publicShop($this->shopEvent($code)));
    }

    /** POST /public/asoebi/{code}/orders */
    public function order(Request $request, string $code): JsonResponse
    {
        $event = $this->shopEvent($code);
        $data = $request->validate([
            'package_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'buyer_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'delivery' => ['sometimes', 'boolean'],
            'delivery_address' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            return ApiResponse::success('Continue to payment.', $this->asoebi->startOrder($event, $data), 201);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), null, 502);
        }
    }

    /** GET /public/asoebi-orders/{token} — the buyer's order, checking a pending payment. */
    public function showOrder(string $token): JsonResponse
    {
        $order = strlen($token) >= 20 ? AsoebiOrder::where('pickup_token', $token)->first() : null;
        abort_unless($order, 404, 'We couldn’t find this order. Check the link in your email.');

        return ApiResponse::success('Order.', $this->asoebi->orderView($this->asoebi->refreshPending($order)));
    }

    /* ── Helpers handing out ──────────────────────── */

    /** GET /public/asoebi-handout/{code} */
    public function handout(string $code): JsonResponse
    {
        $event = $this->handoutEvent($code);

        return ApiResponse::success('Handout.', ['title' => $event->title, 'stats' => $this->asoebi->stats($event)]);
    }

    /** POST /public/asoebi-handout/{code}/scan */
    public function handoutScan(Request $request, string $code): JsonResponse
    {
        $event = $this->handoutEvent($code);
        $scanned = (string) $request->validate(['scanned' => ['required', 'string', 'max:500']])['scanned'];

        $order = $this->asoebi->findByScan($event, $scanned);
        abort_unless($order, 404, 'This code isn’t an order for this aso-ebi.');

        return ApiResponse::success('Order.', ['order' => $this->asoebi->card($order)]);
    }

    /** GET /public/asoebi-handout/{code}/search?q= */
    public function handoutSearch(Request $request, string $code): JsonResponse
    {
        $event = $this->handoutEvent($code);
        $q = (string) $request->validate(['q' => ['required', 'string', 'max:60']])['q'];

        return ApiResponse::success('Orders.', ['orders' => $this->asoebi->search($event, $q)]);
    }

    /** POST /public/asoebi-handout/{code}/orders/{token}/hand-over */
    public function handoutGive(Request $request, string $code, string $token): JsonResponse
    {
        $event = $this->handoutEvent($code);
        $order = $event->asoebiOrders()->where('pickup_token', $token)->first();
        abort_unless($order, 404, 'This code isn’t an order for this aso-ebi.');

        $out = $this->asoebi->handOver($order, AsoebiService::helperName($request->header('X-Usher-Name')));

        return ApiResponse::success(
            ['handed_over' => 'Handed over.', 'already' => 'Already collected.', 'unpaid' => 'This order isn’t paid.'][$out['result']],
            ['result' => $out['result'], 'order' => $this->asoebi->card($out['order']), 'stats' => $this->asoebi->stats($event)],
        );
    }

    /* ── The coordinator ──────────────────────────── */

    /** GET /events/{event}/asoebi */
    public function hostShow(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $filter = (string) $request->query('filter', 'all');

        return ApiResponse::success('Aso-ebi.', $this->hostPayload($event) + [
            'orders' => $this->asoebi->orders($event, $filter, (int) $request->query('page', 1)),
        ]);
    }

    /** PUT /events/{event}/asoebi */
    public function hostUpdate(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'colours' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pickup_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'delivery_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'handout_enabled' => ['sometimes', 'boolean'],
            'new_handout_code' => ['sometimes', 'boolean'],
        ]);

        if (!empty($data['enabled']) && !$event->asoebiPackages()->where('is_active', true)->exists()) {
            return ApiResponse::error('Add at least one package before you open orders.', ['enabled' => ['needs_package']], 422);
        }

        $this->asoebi->updateSettings($event, $data);

        return ApiResponse::success('Aso-ebi settings saved.', $this->hostPayload($event->fresh()));
    }

    /** POST /events/{event}/asoebi/packages */
    public function packageStore(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $this->asoebi->savePackage($event, $this->packageData($request));

        return ApiResponse::success('Package added.', $this->hostPayload($event->fresh()), 201);
    }

    /** PUT /events/{event}/asoebi/packages/{package} */
    public function packageUpdate(Request $request, Event $event, AsoebiPackage $package): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($package->event_id === $event->id, 404);
        $this->asoebi->savePackage($event, $this->packageData($request, true), $package);

        return ApiResponse::success('Package saved.', $this->hostPayload($event->fresh()));
    }

    /** DELETE /events/{event}/asoebi/packages/{package} */
    public function packageDestroy(Event $event, AsoebiPackage $package): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($package->event_id === $event->id, 404);
        $how = $this->asoebi->removePackage($package);

        return ApiResponse::success($how === 'hidden' ? 'Package hidden. It has orders, so it stays on their records.' : 'Package removed.',
            $this->hostPayload($event->fresh()));
    }

    /** POST /events/{event}/asoebi/orders/{order}/hand-over — e.g. a delivery that arrived. */
    public function hostHandOver(Request $request, Event $event, AsoebiOrder $order): JsonResponse
    {
        $this->authorize('update', $event);
        abort_unless($order->event_id === $event->id, 404);
        $out = $this->asoebi->handOver($order, 'Host');

        return ApiResponse::success('Saved.', ['result' => $out['result'], 'order' => $this->asoebi->card($out['order'])]);
    }

    /** POST /events/{event}/asoebi/remind */
    public function remind(Event $event): JsonResponse
    {
        $this->authorize('update', $event);
        $sent = $this->asoebi->remindUnpaid($event);

        return ApiResponse::success($sent ? "Reminder sent to {$sent} " . ($sent === 1 ? 'person.' : 'people.') : 'No one to remind right now.', ['sent' => $sent]);
    }

    /** GET /events/{event}/asoebi/export — paid orders as CSV. */
    public function export(Event $event): StreamedResponse
    {
        $this->authorize('view', $event);
        $rows = $this->asoebi->exportRows($event);
        $name = 'aso-ebi-' . (\Illuminate\Support\Str::slug($event->title) ?: 'orders') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reads the ₦ sign right with a BOM
            foreach ($rows as $row) {
                // Cells starting = + - @ would run as formulas in Excel.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ── Helpers ──────────────────────────────────── */

    private function hostPayload(Event $event): array
    {
        return [
            'settings' => [
                'enabled' => (bool) $event->asoebi_enabled,
                'open' => $this->asoebi->isOpen($event),
                'shop_url' => $this->asoebi->shopUrl($event),
                'deadline' => $event->asoebi_deadline,
                'colours' => $event->asoebi_colours,
                'pickup_note' => $event->asoebi_pickup_note,
                'delivery_fee' => $event->asoebi_delivery_fee !== null ? (float) $event->asoebi_delivery_fee : null,
                'handout_enabled' => (bool) $event->asoebi_handout_enabled,
                'handout_url' => $event->asoebi_handout_enabled ? $this->asoebi->handoutUrl($event) : null,
            ],
            'event' => ['id' => $event->id, 'title' => $event->title],
            'packages' => $this->asoebi->packagesWithSales($event),
            'stats' => $this->asoebi->stats($event),
        ];
    }

    private function packageData(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'price' => [$req, 'numeric', 'min:100', 'max:10000000'],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function shopEvent(string $code): Event
    {
        $event = strlen($code) >= 6 ? Event::where('asoebi_code', strtoupper($code))->with('user')->first() : null;
        abort_unless($event && $event->asoebi_enabled, 404, 'This aso-ebi link isn’t open. Ask the couple or coordinator for the right link.');

        return $event;
    }

    private function handoutEvent(string $code): Event
    {
        $event = strlen($code) >= 6
            ? Event::where('asoebi_handout_code', strtoupper($code))->where('asoebi_handout_enabled', true)->first()
            : null;
        abort_unless($event, 404, 'This handout link isn’t active. Ask the coordinator for the current one.');

        return $event;
    }
}
