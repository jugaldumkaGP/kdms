<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/web_session.php';

$config_data = include dirname(__DIR__) . '/site_config.php';
$eventId = $config_data['event_id'] ?? '';

include_once dirname(__DIR__) . '/api/config/database.php';
include_once dirname(__DIR__) . '/api/Interface/clsKitchenDashboard.php';

$database = new Database();
$db = $database->getConnection();
$kitchen = new clsKitchenDashboard($db);
$rows = $kitchen->getReport(['eventId' => $eventId]);
$row = $rows[0] ?? [];

$residents = (int) ($row['Residents_Printed_For_Event'] ?? 0);
$dayVisitors = (int) ($row['Day_Visitors_Printed_Today'] ?? 0);
$total = (int) ($row['Total_For_Kitchen'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include_once 'header.php'; ?>
    <title>Kitchen Dashboard</title>
    <style>
        .kitchen-metric {
            text-align: center;
            padding: 24px 12px;
        }
        .kitchen-metric .value {
            font-size: 3.5rem;
            font-weight: 700;
            line-height: 1.1;
        }
        .kitchen-metric .label {
            font-size: 1.1rem;
            color: #555;
            margin-top: 8px;
        }
        .card-title .kitchen-info-wrap {
            display: inline-block;
            position: relative;
            vertical-align: middle;
            margin-left: 6px;
            line-height: 1;
        }
        .kitchen-info-icon {
            font-size: 20px !important;
            color: rgba(255, 255, 255, 0.85);
            cursor: help;
            vertical-align: middle;
        }
        .kitchen-info-bubble {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            z-index: 1000;
            left: 50%;
            top: calc(100% + 10px);
            transform: translateX(-50%);
            width: min(420px, 85vw);
            padding: 12px 14px;
            font-size: 0.85rem;
            font-weight: 400;
            line-height: 1.45;
            color: #333;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            text-align: left;
            transition: opacity 0.15s ease, visibility 0.15s ease;
            pointer-events: none;
        }
        .kitchen-info-bubble::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            margin-left: -8px;
            border: 8px solid transparent;
            border-bottom-color: #fff;
            filter: drop-shadow(0 -1px 0 #ddd);
        }
        .kitchen-info-wrap:hover .kitchen-info-bubble,
        .kitchen-info-wrap:focus-within .kitchen-info-bubble {
            visibility: visible;
            opacity: 1;
        }
        #kitchen-refresh-time {
            font-size: 0.85rem;
            color: #888;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include_once 'nav.php'; ?>
    <div class="main-panel">
        <div class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header card-header-primary">
                                <h4 class="card-title">
                                    Kitchen — meal planning
                                    <span class="kitchen-info-wrap">
                                        <i class="material-icons kitchen-info-icon" tabindex="0" role="button" aria-label="How kitchen counts are calculated">info</i>
                                        <span class="kitchen-info-bubble" role="tooltip">
                                            <strong>Residents</strong> — distinct devotees with a card print recorded in
                                            <code>print_log</code> for this event (any date), excluding day visitors (status D, type T).
                                            <strong>Day Visitors Today</strong> — distinct D/T devotees with <code>print_log</code> dated today only.
                                            Queuing via registration does not count until the card is printed.
                                            <strong>Total for kitchen</strong> is the sum of those two figures (no double-count).
                                        </span>
                                    </span>
                                </h4>
                                <p class="card-category">
                                    Event: <?= htmlspecialchars((string) ($row['Event_ID'] ?? $eventId), ENT_QUOTES, 'UTF-8'); ?>
                                    · <span id="kitchen-refresh-time">Updated <?= date('H:i:s'); ?></span>
                                </p>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 kitchen-metric">
                                        <div class="value" id="metric-residents"><?= $residents; ?></div>
                                        <div class="label">Residents</div>
                                    </div>
                                    <div class="col-md-4 kitchen-metric">
                                        <div class="value" id="metric-day-visitors"><?= $dayVisitors; ?></div>
                                        <div class="label">Day Visitors Today</div>
                                    </div>
                                    <div class="col-md-4 kitchen-metric">
                                        <div class="value" id="metric-total"><?= $total; ?></div>
                                        <div class="label">Total for kitchen</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include_once 'scriptJS.php'; ?>
<script>
(function () {
    var refreshMs = 5 * 60 * 1000;

    function refreshKitchenCounts() {
        fetch('getKitchenCounts.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status !== 'success') {
                    return;
                }
                document.getElementById('metric-residents').textContent = data.residentsToday;
                document.getElementById('metric-day-visitors').textContent = data.dayVisitorsPrintedToday;
                document.getElementById('metric-total').textContent = data.totalForKitchen;
                document.getElementById('kitchen-refresh-time').textContent =
                    'Updated ' + (data.refreshTime || '');
            })
            .catch(function () {});
    }

    setInterval(refreshKitchenCounts, refreshMs);
})();
</script>
</body>
</html>
