{{--
    Operator dashboard. Read-only: every mutation goes through the API or the
    console, so there is one code path per operation rather than two that drift.

    Blade escapes by default ({{ }}), which is the XSS defence here. Nothing on
    this page uses {!! !!}.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Inventory Reservation Engine</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f6f7f9; }
        .metric { font-variant-numeric: tabular-nums; }
        .table-sm td, .table-sm th { padding: .4rem .5rem; }
        .low-stock { background: #fff4f4 !important; }
        .card { border: 1px solid #e6e8eb; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand mb-0 h1">Warehouse Inventory Reservation Engine</span>
        <span class="navbar-text small" id="last-updated"></span>
    </div>
</nav>

<div class="container-fluid py-4">

    <form method="GET" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
            <label for="warehouse_id" class="form-label small text-muted mb-1">Warehouse</label>
            <select name="warehouse_id" id="warehouse_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All warehouses</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected($selectedWarehouse === $warehouse->id)>
                        {{ $warehouse->code }} — {{ $warehouse->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <span class="badge text-bg-secondary">{{ $snapshots->count() }} positions</span>
            <span class="badge text-bg-primary">{{ $openReservations->count() }} open reservations</span>
            @if ($expiringSoon->isNotEmpty())
                <span class="badge text-bg-warning">{{ $expiringSoon->count() }} expiring within 10 min</span>
            @endif
        </div>
    </form>

    <div class="row g-4">

        {{-- ---------------------------------------------------------------
             Stock positions. available = on_hand - reserved is the only
             number the sales side should ever act on, so it leads.
        ---------------------------------------------------------------- --}}
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header bg-white fw-semibold">Stock positions</div>
                <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light sticky-top">
                        <tr>
                            <th>Warehouse</th>
                            <th>SKU</th>
                            <th class="text-end">Available</th>
                            <th class="text-end">On hand</th>
                            <th class="text-end">Reserved</th>
                            <th class="text-end">Shipped</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody id="stock-body">
                        @forelse ($snapshots as $snapshot)
                            <tr class="{{ $snapshot->available() <= 0 ? 'low-stock' : '' }}">
                                <td class="text-muted small">{{ $snapshot->warehouseCode }}</td>
                                <td><code>{{ $snapshot->productSku }}</code></td>
                                <td class="text-end metric fw-semibold">{{ $snapshot->available() }}</td>
                                <td class="text-end metric">{{ $snapshot->onHand }}</td>
                                <td class="text-end metric">{{ $snapshot->reserved }}</td>
                                <td class="text-end metric text-muted">{{ $snapshot->shippedToDate }}</td>
                                <td class="text-end">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary py-0 js-history"
                                            data-product="{{ $snapshot->productId }}"
                                            data-warehouse="{{ $snapshot->warehouseId }}"
                                            data-label="{{ $snapshot->productSku }} @ {{ $snapshot->warehouseCode }}">
                                        history
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">
                                No stock positions yet. Run <code>php artisan db:seed</code>.
                            </td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">

            {{-- Open reservations: what is currently blocking availability. --}}
            <div class="card mb-4">
                <div class="card-header bg-white fw-semibold">Open reservations</div>
                <div class="table-responsive" style="max-height: 260px; overflow-y: auto;">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light sticky-top">
                        <tr>
                            <th>SKU</th><th>WH</th>
                            <th class="text-end">Held</th>
                            <th>Status</th><th>Expires</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($openReservations as $reservation)
                            <tr>
                                <td><code>{{ $reservation->product?->sku }}</code></td>
                                <td class="small text-muted">{{ $reservation->warehouse?->code }}</td>
                                <td class="text-end metric">{{ $reservation->qtyOutstanding() }}</td>
                                <td><span class="badge {{ $reservation->status->badgeClass() }}">{{ $reservation->status->label() }}</span></td>
                                <td class="small text-muted">
                                    {{ $reservation->expires_at?->diffForHumans() ?? 'never' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">Nothing reserved.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Shipments: `dispatched` is the state worth watching — outcome
                 unknown, reservation still held, no stock deducted yet. --}}
            <div class="card">
                <div class="card-header bg-white fw-semibold">Recent shipments</div>
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                    <tr><th>#</th><th>WH</th><th>Status</th><th class="text-end">Attempts</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($recentShipments as $shipment)
                        <tr>
                            <td class="text-muted">{{ $shipment->id }}</td>
                            <td class="small text-muted">{{ $shipment->warehouse?->code }}</td>
                            <td><span class="badge {{ $shipment->status->badgeClass() }}">{{ $shipment->status->label() }}</span></td>
                            <td class="text-end metric">{{ $shipment->attempts }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No shipments.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

{{-- Movement history drill-down — the audit trail behind every number above. --}}
<div class="modal fade" id="history-modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="history-title">Movement history</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>When</th><th>Type</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">On hand Δ</th>
                        <th class="text-end">Reserved Δ</th>
                        <th class="text-end">On hand after</th>
                        <th>Actor</th>
                    </tr>
                    </thead>
                    <tbody id="history-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(function () {
    var $modal = $('#history-modal');

    // Escape everything from the server before it touches the DOM. The values
    // are our own, but building HTML from strings without escaping is how an
    // XSS ships the day somebody adds a customer-supplied SKU.
    function esc(value) {
        return $('<div>').text(value === null || value === undefined ? '' : value).html();
    }

    function signed(n) {
        return n > 0 ? '+' + n : String(n);
    }

    $('.js-history').on('click', function () {
        var product = $(this).data('product');
        var warehouse = $(this).data('warehouse');

        $('#history-title').text('Movement history — ' + $(this).data('label'));
        $('#history-body').html('<tr><td colspan="7" class="text-center text-muted py-3">Loading…</td></tr>');
        $modal.modal('show');

        $.getJSON('/dashboard/movements/' + product + '/' + warehouse)
            .done(function (response) {
                if (!response.data.length) {
                    $('#history-body').html('<tr><td colspan="7" class="text-center text-muted py-3">No movements.</td></tr>');
                    return;
                }

                var rows = response.data.map(function (m) {
                    return '<tr>' +
                        '<td class="small text-muted">' + esc(m.occurred_at) + '</td>' +
                        '<td><span class="badge ' + esc(m.badge) + '">' + esc(m.label) + '</span></td>' +
                        '<td class="text-end metric">' + esc(m.qty) + '</td>' +
                        '<td class="text-end metric">' + esc(signed(m.on_hand_delta)) + '</td>' +
                        '<td class="text-end metric">' + esc(signed(m.reserved_delta)) + '</td>' +
                        '<td class="text-end metric">' + esc(m.on_hand_after) + '</td>' +
                        '<td class="small text-muted">' + esc(m.actor) + '</td>' +
                        '</tr>';
                });

                $('#history-body').html(rows.join(''));
            })
            .fail(function () {
                $('#history-body').html('<tr><td colspan="7" class="text-center text-danger py-3">Failed to load history.</td></tr>');
            });
    });

    // Live stock refresh. Polling rather than websockets on purpose: this is an
    // operator screen refreshed by a handful of people, and adding a broadcast
    // stack for it would be infrastructure with no corresponding benefit.
    function refresh() {
        var warehouse = $('#warehouse_id').val();

        $.getJSON('/dashboard/snapshot', warehouse ? { warehouse_id: warehouse } : {})
            .done(function (response) {
                var map = {};
                response.data.forEach(function (s) {
                    map[s.product_id + ':' + s.warehouse_id] = s;
                });

                $('#stock-body tr').each(function () {
                    var $button = $(this).find('.js-history');
                    if (!$button.length) return;

                    var snapshot = map[$button.data('product') + ':' + $button.data('warehouse')];
                    if (!snapshot) return;

                    var $cells = $(this).find('td');
                    $cells.eq(2).text(snapshot.available);
                    $cells.eq(3).text(snapshot.on_hand);
                    $cells.eq(4).text(snapshot.reserved);
                    $cells.eq(5).text(snapshot.shipped_to_date);

                    $(this).toggleClass('low-stock', snapshot.available <= 0);
                });

                $('#last-updated').text('updated ' + new Date().toLocaleTimeString());
            });
    }

    setInterval(refresh, 5000);
    refresh();
});
</script>
</body>
</html>
