<?php

declare(strict_types=1);

/**
 * Permission key per UI script (basename). Must match Access entries for each role.
 */
return [
    'debugger.php'             => 'KD-DSBRD',
    'index.php'                => 'KD-DSBRD',
    'registration.php'         => 'KD-REG',
    'displayDevotees.php'      => 'KD-DISP-DVT',
    'printID.php'              => 'KD-PRT-ID',
    'reports.php'              => 'KD-RPT-SEVX',
    'recovery.php'             => 'KD-ACC-REC',
    'registrationCounts.php'   => 'KD-REG-DASH',
    'getRegistrationCounts.php'=> 'KD-REG-DASH',
    'rptDutyReport.php'        => 'KD-RPT-DUTY',
    // Same permission as devoteeSearchResult: card print is opened from search; do not require a separate grant.
    'rptCardsPrint.php'        => 'KD-DVT-SCR',
    'rptCardsPrintTemp.php'    => 'KD-DVT-SCR',
    'old_rptCardsPrint.php'    => 'KD-DVT-SCR',
    'rptCardPrint.php'         => 'KD-RPT-CARD-TMP',
    'addAccommodationI.php'   => 'KD-ACCO-I',
    'addAccommodationII.php'  => 'KD-ACCO-II',
    'addDevoteeI.php'          => 'KD-DVT-I',
    // Same-host Logic proxies (staff add-devotee photo/OCR/dedup/merge); require web_session ACL.
    'managePhotoProxy.php'     => 'KD-DVT-I',
    'staffOcrExtractProxy.php' => 'KD-DVT-I',
    'dedupHintsProxy.php'      => 'KD-DVT-I',
    'dedupCheckProxy.php'      => 'KD-DVT-I',
    'devoteePhotoProxy.php'    => 'KD-DVT-I',
    'adminMergeProxy.php'      => 'KD-DVT-I',
    'addSevaI.php'             => 'KD-SEVA-I',
    'addSevaII.php'            => 'KD-SEVA-II',
    'AddSevaII.php'             => 'KD-SEVA-II',
    'devoteeSearchResult.php'  => 'KD-DVT-SCR',
    'upsertAmenityI.php'       => 'KD-AMT-I',
    'upsertAmenityII.php'      => 'KD-AMT-II',
    'upsertEventI.php'         => 'KD-EVNT-I',
    'upsertEventII.php'        => 'KD-EVNT-II',
    'OCRReaderView.php'        => 'KD-DVT-SCR',
    'kitchenDashboard.php'     => 'KD-KITCHEN',
    'getKitchenCounts.php'       => 'KD-KITCHEN',
];
