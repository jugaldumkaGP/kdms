<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/web_session.php';
require_once dirname(__DIR__) . '/includes/kdms_card_photo_block.php';

/** @var array<string,mixed> $config_data Set by initialize.php via web_session; fallback ensures tooling/runtime always have site config */
$config_data ??= include dirname(__DIR__) . '/site_config.php';

$eventId = isset($config_data['event_id']) ? (string) $config_data['event_id'] : '';
$debug = false;

$devotees_to_print = [];

if (!empty($_GET['key'])) {
    include_once dirname(__DIR__) . '/Logic/clsDevoteeSearch.php';
    // Assuming clsDevoteeSearch handles sanitization of $_GET['key'] internally
    $devoteeSearch = new clsDevoteeSearch($_GET);
    $response = $devoteeSearch->getDevoteeRecords($eventId);
    unset($devoteeSearch);

    if (is_array($response) || is_object($response)) {
        foreach ((array) $response as $idx => $devoteeRecord) {
            if (in_array((string) $idx, ['status', 'message', 'info'], true)) {
                continue;
            }
            if (is_object($devoteeRecord)) {
                $devoteeRecord = (array) $devoteeRecord;
            }
            if (! is_array($devoteeRecord)) {
                continue;
            }
            $get_val = function ($key, $default = "N/A") use ($devoteeRecord) {
                return !empty($devoteeRecord[$key]) ? urldecode((string) $devoteeRecord[$key]) : $default;
            };

            $devotee_key = $get_val('devotee_key');

            // Only process if a devotee key exists
            if ($devotee_key !== "N/A") {
                $devotees_to_print[] = [
                    'key'                   => $devotee_key,
                    'first_name'            => $get_val('devotee_first_name'),
                    'last_name'             => $get_val('devotee_last_name'),
                    'station'               => $get_val('devotee_station'),
                    'status'                => $get_val('devotee_status'),
                    'devotee_type'          => $get_val('Devotee_Type'),
                    'cell_phone_number'     => $get_val('devotee_cell_phone_number'),
                    'devotee_referral'      => $get_val('Devotee_Referral'),
                    'accommodation_name'    => $get_val('accomodation_name'), // Check spelling: accomodation vs accommodation
                    'photo'                 => !empty($devoteeRecord['Devotee_Photo']) ? $devoteeRecord['Devotee_Photo'] : "",
                ];
            }
        }
    }
}

if ($debug && isset($response)) { // Check if $response is set before var_dump
    var_dump($response);
    // To debug processed data: var_dump($devotees_to_print);
    die;
}

$webroot = isset($config_data['webroot']) ? rtrim((string) $config_data['webroot'], '/') . '/' : '';
$bannerImgSrc = $webroot . 'assets/img/banner.png';
?>
<html>
<head>
    <title> Card Print </title>
    <style>
        body {
            font-family: sans-serif;
            margin: 0;
            padding: 0;
        }
        #printpage {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            box-sizing: border-box;
            padding: 12px 0;
        }
        .card-item {
            background-color: #ffffff;
            border-radius: 4px;
            border: 2px solid #2c2c2c;
            min-height: 220px;
            width: 324px;
            max-width: calc(100% - 16px);
            margin-left: auto;
            margin-right: auto;
            margin: 0 auto 7px auto;
            page-break-inside: avoid;
            overflow: hidden;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            box-sizing: border-box;
        }
        .card-item img.banner {
            display: block;
            width: 100%;
            height: 35px;
            object-fit: fill;
        }
        .card-accent-strip {
            height: 3px;
            background-color: #888888;
            width: 100%;
        }
        .card-label {
            text-align: left;
            width: 80px;
            float: left;
            clear: left;
            margin-right: 5px;
            font-weight: bold;
            font-size: 12px;
            color: #666666;
            padding-top: 2px;
        }
        .card-data {
            font-size: 12px;
            color: #111111;
            padding-top: 2px;
            display: block;
            margin-left: 85px;
            word-wrap: break-word;
        }
        .devotee-name {
            display: block;
            text-align: left;
            font-weight: bold;
            font-size: 20px;
            color: #1a1a1a;
            padding-bottom: 5px;
            margin-bottom: 6px;
            border-bottom: 2px solid #555555;
        }
        .devotee-status {
            display: block;
            text-align: center;
            width: 100%;
            font-weight: bold;
            font-size: 26px;
            margin-bottom: 3px;
        }
        .devotee-status.blocked { color: red; }
        .card-footer {
            font-size: 10px;
            text-align: center;
            color: #555555;
            display: block;
            margin-top: 6px;
            padding-top: 5px;
            border-top: 1px solid #e8d5c0;
        }
        .details-row {
            overflow: hidden;
            margin-bottom: 1px;
        }
        .card-body {
            padding: 6px 8px 5px 8px;
        }
        .photo-badge {
            display: inline-block;
            border: 2px solid #555555;
            border-radius: 3px;
            overflow: hidden;
            line-height: 0;
        }
        .photo-badge img {
            display: block;
            width: 90px;
            height: 100px;
            object-fit: cover;
        }
        .year-band {
            background-color: #e0e0e0;
            color: #111111;
            font-size: 22px;
            font-weight: bold;
            text-align: center;
            line-height: 1;
            padding: 3px 0;
        }
        /* Print-specific styles */
        @media print {
            @page {
                margin: 8mm;
            }
            body {
                margin: 0;
                padding: 0;
                font-family: sans-serif;
            }
            #printpage {
                display: flex;
                flex-direction: column;
                align-items: center;
                width: 100%;
                padding: 0;
            }
            .card-item {
                margin-left: auto;
                margin-right: auto;
                margin-bottom: 7mm;
                page-break-inside: avoid;
                page-break-after: auto;
            }
            .no-print {
                display: none;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Automatically trigger print dialog if there's content to print
            if (document.getElementById("printpage").children.length > 0 &&
                !document.getElementById("printpage").textContent.includes("No devotee records found")) {
                printDivContent();
            }
        }, false);

        function printDivContent() {
            var printContent = document.getElementById("printpage").innerHTML;
            var pageStyles = "";
            // Collect all style rules
            for (let i = 0; i < document.styleSheets.length; i++) {
                try {
                    var rules = document.styleSheets[i].cssRules || document.styleSheets[i].rules;
                    for (let j = 0; j < rules.length; j++) {
                        pageStyles += rules[j].cssText + "\n";
                    }
                } catch (e) {
                    // Catch potential cross-origin stylesheet errors
                    console.warn("Could not read styles from stylesheet: " + document.styleSheets[i].href, e);
                }
            }

            var popupWin = window.open('', '_blank', 'width=800,height=700,scrollbars=yes,resizable=yes');
            popupWin.document.open();
            popupWin.document.write('<html><head><title>Print Card</title>');
            popupWin.document.write('<style type="text/css">' + pageStyles + '</style>');
            popupWin.document.write('</head><body onload="window.print(); window.close();">');
            popupWin.document.write('<div id="printpage">');
            popupWin.document.write(printContent);
            popupWin.document.write('</div>');
            popupWin.document.write('</body></html>');
            popupWin.document.close();

            // Optional: Close the original window if this page is only a launcher
            // window.close();
            return false;
        }
    </script>
</head>
<body>

<div id="printpage">
    <?php if (!empty($devotees_to_print)): ?>
        <?php foreach ($devotees_to_print as $index => $devotee): ?>
        <div class="card-item" id="card-<?php echo $index; ?>">
            <img src="<?php echo htmlspecialchars($bannerImgSrc); ?>" height="35px" width="100%" alt="Banner" class="banner" style="max-width:360px;">
            <div class="card-accent-strip"></div>
            <div class="card-body">
                <!-- This is for prasad vitran -->
                <?php if ($devotee['status'] == "D" && $devotee['devotee_type'] == "T" && stripos($devotee['devotee_referral'], 'devesh') === 0) : // Day Visitor Card ?>
                <span class="devotee-name" style="text-align:center;">
                    <?php echo htmlspecialchars($devotee['first_name'] . ' ' . $devotee['last_name']); ?>
                </span>
                    <span class="devotee-status" style="font-size: 14px;">
                        <?php echo '( ' . $devotee['key'] . ' )'; ?>
                    </span>
                    <span class="devotee-status" style="margin:0;">Prasad Vitran</span>
                    <hr style="width: 80%; margin: 5px 0; margin: 0 auto;">

                        <span style="display: block; text-align: center; margin-bottom: 2px; margin-top: 10px; font-size: 14px; font-weight: bold;">Referred by: </span>
                        <div class="details-row" style="margin-top: 0; margin-bottom:10px;">
                            <div class="details-row" style="text-align: center;">
                                <span class="card-data"
                                      style="font-size: 16px; font-weight: bold; display: inline-block; margin-left: 0;">
                                    <?php echo 'Devesh Agarwal Ji'; ?>
                                </span>
                            </div>
                        </div>
                        <span class="card-footer">
                            This card is not valid after 15th June <strong>2025</strong>
                        </span>
                <?php elseif ($devotee['status'] == "D" && $devotee['devotee_type'] == "T"): // Day Visitor Card ?>
                <span class="devotee-name" style="text-align:center;">
                    <?php echo htmlspecialchars($devotee['first_name'] . ' ' . $devotee['last_name']); ?>
                </span>
                    <?php if (!empty($devotee['accommodation_name']) && $devotee['accommodation_name'] !== "N/A" && $devotee['accommodation_name'] !== "Own Arrangement (Outside)" && $devotee['accommodation_name'] !== "Local"): ?>
                    <span class="devotee-status" style="margin: 20px 0; margin: 5px 0; font-size: 24px;">Temporary Accommodation for 2025</span>
                    <?php else: ?>
                    <span class="devotee-status" style="margin: 20px 0; margin: 5px 0;">Temporary</span>
                    <?php endif; ?>
                    <hr style="width: 80%; margin: 5px 0; margin: 0 auto;">
                    <?php if (!empty($devotee['accommodation_name']) && $devotee['accommodation_name'] !== "N/A" && $devotee['accommodation_name'] !== "Own Arrangement (Outside)" && $devotee['accommodation_name'] !== "Local"): ?>
                        <span style="display: block; text-align: center; margin-bottom: 2px; margin-top: 10px; font-size: 14px; font-weight: bold;">Staying at: </span>
                        <div class="details-row" style="margin-top: 0; margin-bottom:10px;">
                            <div class="details-row" style="text-align: center;">
                                <span class="card-data"
                                      style="font-size: 16px; font-weight: bold; display: inline-block; margin-left: 0;">
                                    <?php echo htmlspecialchars($devotee['accommodation_name']); ?>
                                </span>
                            </div>
                        </div>
                    <?php elseif (!empty($devotee['devotee_referral']) && $devotee['devotee_referral'] !== "N/A"): ?>
                        <span style="display: block; text-align: center; margin-bottom: 2px; margin-top: 10px; font-size: 14px; font-weight: bold;">Referred by: </span>
                        <div class="details-row" style="margin-top: 0; margin-bottom:10px;">
                            <div class="details-row" style="text-align: center;">
                                <span class="card-data"
                                      style="font-size: 16px; font-weight: bold; display: inline-block; margin-left: 0;">
                                    <?php echo htmlspecialchars($devotee['devotee_referral']); ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php // Photo and other details are intentionally omitted for Day Visitor ?>

                <?php else: // Standard Card (including Blocked, etc.) ?>
                    <table style="width:100%;">
                        <tr>
                            <td style="width:65%; vertical-align:top;">
                                <span class="devotee-name">
                                    <?php
                                        $full_name = $devotee['first_name'] . ' ' . $devotee['last_name'];
                                        $display_name = strlen($full_name) > 15
                                            ? $devotee['first_name'] . ' ' . strtoupper(substr($devotee['last_name'], 0, 1)) . '.'
                                            : $full_name;
                                        echo htmlspecialchars($display_name);
                                    ?>
                                </span>
                                <div class="details-row">
                                    <span class="card-label">Reg No.:</span>
                                    <span class="card-data"><?php echo htmlspecialchars($devotee['key']); ?></span>
                                </div>
                                <div class="details-row">
                                    <span class="card-label">Station:</span>
                                    <span class="card-data"><?php echo htmlspecialchars($devotee['station']); ?></span>
                                </div>
                                <div class="details-row">
                                    <span class="card-label">Staying at:</span>
                                    <span class="card-data"><?php echo htmlspecialchars($devotee['accommodation_name']); ?></span>
                                </div>
                                <div class="details-row">
                                    <span class="card-label">Mobile No:</span>
                                    <span class="card-data"><?php echo htmlspecialchars($devotee['cell_phone_number']); ?></span>
                                </div>
                            </td>
                            <td style="width:35%; text-align:center; vertical-align:top; padding-top:2px; padding-right:6px;">
                                <div class="photo-badge">
                                    <?php if (empty($devotee['photo'])): ?>
                                        <img src="<?php echo htmlspecialchars($webroot . 'assets/img/faces/devotee.ico'); ?>" alt="Devotee Image">
                                    <?php else: ?>
                                        <img src="data:image/jpeg;base64,<?php echo htmlspecialchars($devotee['photo']); ?>" alt="Devotee Image">
                                    <?php endif; ?>
                                    <div class="year-band"><?php echo date('Y'); ?></div>
                                </div>
                            </td>
                        </tr>
                    </table>
                    <?php if ($devotee['status'] == "B"): ?>
                    <span class="devotee-status blocked">BLOCKED</span>
                    <?php endif; ?>
                    <span class="card-footer">
                        This card is not valid after <?php echo isset($_SESSION['eventDesc']) ? htmlspecialchars($_SESSION['eventDesc']) : 'EVENT_END_DATE'; ?>
                    </span>
                <?php endif; // End of conditional card content ?>

            </div>
        </div>
        <?php if (count($devotees_to_print) > 1 && ($index < count($devotees_to_print) - 1)): ?>
            <div style="page-break-after: always;" class="no-print"></div> <!-- Ensures page break for printing multiple cards -->
        <?php endif; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <p>No devotee records found to print.</p>
    <?php endif; ?>
</div>

<div class="no-print" style="text-align:left; margin-top:20px;">
    <button type="button" onclick="printDivContent();">Print Cards</button>
    <!-- The form below was part of the original code. Its purpose is unclear without the AJAX context. -->
    <!-- If it's related to 'removeFromPrintQueue', that logic needs to be implemented, possibly via AJAX after printing. -->
    <form id="printForm" action="<?php echo htmlspecialchars($config_data['webroot']); ?>Logic/requestManager.php" method="POST" style="display:none;">
        <input type="hidden" name="requestType" value="removeFromPrintQueue_placeholder">
        <input type="hidden" name="devotee_key_data" value="<?php echo htmlspecialchars($_GET['key'] ?? ''); ?>">
    </form>
</div>

</body>
</html>
