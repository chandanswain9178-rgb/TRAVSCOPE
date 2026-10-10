<?php

/*
|--------------------------------------------------------------------------
| TRAVSCOPE PROFESSIONAL CRM
| FULL CUSTOMER PACKAGE STUDIO VERSION: 2026-08-06-R12
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
$pexelsHttpFunctionsFile = __DIR__ . '/pexels-http-functions.php';
if (is_file($pexelsHttpFunctionsFile)) require_once $pexelsHttpFunctionsFile;
$receiptPdfFunctionsFile = __DIR__ . '/receipt-pdf-functions.php';
if (is_file($receiptPdfFunctionsFile) && !function_exists('tsReceiptPdfPngRgb')) require_once $receiptPdfFunctionsFile;
$packageSupplierFunctionsFile = __DIR__ . '/package-supplier-functions.php';
if (is_file($packageSupplierFunctionsFile)) require_once $packageSupplierFunctionsFile;
$commercialWorkflowFile = __DIR__ . '/commercial-workflow-functions.php';
if (is_file($commercialWorkflowFile)) require_once $commercialWorkflowFile;
$hotelAllocationFunctionsFile = __DIR__ . '/hotel-allocation-functions.php';
if (is_file($hotelAllocationFunctionsFile)) require_once $hotelAllocationFunctionsFile;
$travelSuggestionFunctionsFile = __DIR__ . '/travel-suggestion-functions.php';
if (is_file($travelSuggestionFunctionsFile)) require_once $travelSuggestionFunctionsFile;

$accessControlFunctionsFile = __DIR__ . '/access-control-functions.php';
if (is_file($accessControlFunctionsFile)) {
    require_once $accessControlFunctionsFile;
}

/* STEP 11G - SUPPLIER VEHICLE / TRANSPORT ALLOCATION */
$transportAllocationFunctionsFile = __DIR__ . '/transport-allocation-functions.php';
if (is_file($transportAllocationFunctionsFile) && !function_exists('tsTABuildForQuotation')) { require_once $transportAllocationFunctionsFile; }


$systemSettingsFunctionsFile = __DIR__ . '/system-settings-functions.php';
if (
    is_file($systemSettingsFunctionsFile)
    && !function_exists('tsSystemSettingFloat')
) {
    require_once $systemSettingsFunctionsFile;
}


/*
|--------------------------------------------------------------------------
| PUBLIC SIGNED QUOTATION PDF LINK
|--------------------------------------------------------------------------
|
| Email / WhatsApp recipients must be able to download only the exact
| quotation that was shared with them without needing an admin login.
|
| The link is protected by an HMAC signature generated on the server.
| Normal CRM/admin pages still require the existing admin session.
|
*/

function crmQuotationPublicSecret(): string
{
    foreach ([
        defined('APP_KEY') ? (string)APP_KEY : '',
        defined('GOOGLE_CLIENT_SECRET') ? (string)GOOGLE_CLIENT_SECRET : '',
        defined('SMTP_PASSWORD') ? (string)SMTP_PASSWORD : '',
    ] as $candidate) {
        $candidate = trim($candidate);

        if ($candidate !== '') {
            return hash(
                'sha256',
                'travscope-crm-quotation|' . $candidate
            );
        }
    }

    /*
    | Last-resort site-specific secret. It remains server-side and is never
    | exposed in the generated link.
    */
    return hash(
        'sha256',
        __FILE__
        . '|'
        . (defined('BASE_URL') ? BASE_URL : 'TRAVSCOPE')
    );
}


function crmQuotationPublicToken(
    int $leadId,
    int $versionId
): string {
    return hash_hmac(
        'sha256',
        $leadId . ':' . $versionId,
        crmQuotationPublicSecret()
    );
}


function crmQuotationPublicTokenValid(
    int $leadId,
    int $versionId,
    string $token
): bool {
    if (
        $leadId <= 0
        ||
        $versionId <= 0
        ||
        $token === ''
    ) {
        return false;
    }

    return hash_equals(
        crmQuotationPublicToken(
            $leadId,
            $versionId
        ),
        $token
    );
}


$publicPdfLeadId =
    max(
        0,
        (int)(
            $_GET['lead']
            ?? 0
        )
    );

$publicPdfVersionId =
    max(
        0,
        (int)(
            $_GET['version']
            ?? 0
        )
    );

$publicPdfRequest =
    !empty($_GET['quotation_pdf'])
    &&
    crmQuotationPublicTokenValid(
        $publicPdfLeadId,
        $publicPdfVersionId,
        trim(
            (string)(
                $_GET['token']
                ?? ''
            )
        )
    );


if (
    empty($_SESSION['admin_id'])
    &&
    !$publicPdfRequest
) {
    header(
        'Location: '
        . BASE_URL
        . 'login.php'
    );
    exit;
}

$adminId =
    (int)(
        $_SESSION['admin_id']
        ?? 0
    );

$crmDefaultMarkupType =
    function_exists('tsSystemSettingRaw')
        ? tsSystemSettingRaw(
            $pdo,
            'ops_default_markup_type',
            'fixed'
        )
        : 'fixed';

if (
    !in_array(
        $crmDefaultMarkupType,
        ['fixed','percent'],
        true
    )
) {
    $crmDefaultMarkupType = 'fixed';
}

$crmDefaultMarkupValue =
    function_exists('tsSystemSettingFloat')
        ? tsSystemSettingFloat(
            $pdo,
            'ops_default_markup_value',
            0
        )
        : 0.0;

if (
    $crmDefaultMarkupType === 'percent'
) {
    $crmDefaultMarkupValue =
        min(
            100,
            $crmDefaultMarkupValue
        );
}

$aclCanViewSupplierCost =
    $adminId > 0
    && function_exists('tsAclCan')
    ? tsAclCan($pdo, $adminId, 'view_supplier_cost')
    : true;

$aclCanViewMargin =
    $adminId > 0
    && function_exists('tsAclCan')
    ? tsAclCan($pdo, $adminId, 'view_margin')
    : true;

$aclCanEditMarkup =
    $adminId > 0
    && function_exists('tsAclCan')
    ? tsAclCan($pdo, $adminId, 'edit_markup')
    : true;

$aclCanApproveDiscount =
    $adminId > 0
    && function_exists('tsAclCan')
    ? tsAclCan($pdo, $adminId, 'approve_discount')
    : true;

$aclCanSendQuotation =
    $adminId > 0
    && function_exists('tsAclCan')
    ? tsAclCan($pdo, $adminId, 'send_quotation')
    : true;


/*
|--------------------------------------------------------------------------
| QUOTATION VERSION EMAIL CONVERSATION
|--------------------------------------------------------------------------
|
| Each quotation version has a separate email conversation.
| Outgoing quotation/reply emails are stored here.
| Customer replies are synchronized from Gmail through IMAP when available.
|
*/

$pdo->exec("
    CREATE TABLE IF NOT EXISTS crm_quotation_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NOT NULL,
        direction ENUM('outbound','inbound') NOT NULL,
        sender_email VARCHAR(190) NULL,
        recipient_email VARCHAR(190) NULL,
        subject VARCHAR(255) NULL,
        body_text MEDIUMTEXT NULL,
        message_id VARCHAR(255) NOT NULL,
        in_reply_to VARCHAR(255) NULL,
        sent_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_quotation_message_id (message_id),
        KEY idx_quotation_thread (lead_id, version_id, sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

function crmQuotationThreadTag(int $versionId): string
{
    return '[TRAVSCOPE-QV-' . $versionId . ']';
}

function crmStoreQuotationMessage(
    PDO $pdo,
    int $leadId,
    int $versionId,
    string $direction,
    string $senderEmail,
    string $recipientEmail,
    string $subject,
    string $body,
    ?string $messageId = null,
    ?string $inReplyTo = null,
    ?string $sentAt = null
): void {
    $messageId = trim((string)$messageId);

    if ($messageId === '') {
        $messageId =
            'local-'
            . $direction
            . '-'
            . $leadId
            . '-'
            . $versionId
            . '-'
            . bin2hex(random_bytes(10));
    }

    $stmt =
        $pdo->prepare("
            INSERT INTO crm_quotation_messages
            (
                lead_id,
                version_id,
                direction,
                sender_email,
                recipient_email,
                subject,
                body_text,
                message_id,
                in_reply_to,
                sent_at
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                subject = VALUES(subject),
                body_text = VALUES(body_text),
                in_reply_to = VALUES(in_reply_to),
                sent_at = VALUES(sent_at)
        ");

    $stmt->execute([
        $leadId,
        $versionId,
        $direction,
        $senderEmail !== '' ? $senderEmail : null,
        $recipientEmail !== '' ? $recipientEmail : null,
        $subject !== '' ? $subject : null,
        $body !== '' ? $body : null,
        $messageId,
        $inReplyTo !== '' ? $inReplyTo : null,
        $sentAt ?: date('Y-m-d H:i:s'),
    ]);
}

function crmDecodeImapHeader(string $value): string
{
    if ($value === '' || !function_exists('imap_mime_header_decode')) {
        return $value;
    }

    $parts = @imap_mime_header_decode($value);

    if (!is_array($parts)) {
        return $value;
    }

    $output = '';

    foreach ($parts as $part) {
        $chunk = (string)($part->text ?? '');
        $charset = strtoupper((string)($part->charset ?? 'DEFAULT'));

        if (
            $charset !== 'DEFAULT'
            && $charset !== 'UTF-8'
            && function_exists('mb_convert_encoding')
        ) {
            $chunk =
                @mb_convert_encoding(
                    $chunk,
                    'UTF-8',
                    $charset
                )
                ?: $chunk;
        }

        $output .= $chunk;
    }

    return trim($output);
}

function crmImapMessageText($imap, int $messageNo, $structure, string $partNumber = ''): string
{
    if (!$structure) {
        return '';
    }

    $type = (int)($structure->type ?? 0);
    $subtype = strtoupper((string)($structure->subtype ?? ''));

    if (
        $type === 0
        && in_array($subtype, ['PLAIN', 'HTML'], true)
    ) {
        $body =
            $partNumber !== ''
                ? @imap_fetchbody($imap, $messageNo, $partNumber, FT_PEEK)
                : @imap_body($imap, $messageNo, FT_PEEK);

        $body = (string)$body;

        $encoding = (int)($structure->encoding ?? 0);

        if ($encoding === 3) {
            $decoded = base64_decode($body, true);
            if ($decoded !== false) {
                $body = $decoded;
            }
        } elseif ($encoding === 4) {
            $body = quoted_printable_decode($body);
        }

        if ($subtype === 'HTML') {
            $body =
                preg_replace(
                    '/<(br|br\/|p|div|li)[^>]*>/i',
                    "\n",
                    $body
                )
                ?? $body;

            $body =
                html_entity_decode(
                    strip_tags($body),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
        }

        return trim(
            preg_replace(
                "/\n{3,}/",
                "\n\n",
                str_replace(["\r\n", "\r"], "\n", $body)
            )
            ?? $body
        );
    }

    if (!empty($structure->parts) && is_array($structure->parts)) {
        $plain = '';
        $html = '';

        foreach ($structure->parts as $index => $part) {
            $child =
                $partNumber === ''
                    ? (string)($index + 1)
                    : $partNumber . '.' . ($index + 1);

            $content =
                crmImapMessageText(
                    $imap,
                    $messageNo,
                    $part,
                    $child
                );

            if ($content === '') {
                continue;
            }

            $childSubtype =
                strtoupper(
                    (string)($part->subtype ?? '')
                );

            if ($childSubtype === 'PLAIN') {
                $plain .= ($plain !== '' ? "\n\n" : '') . $content;
            } elseif ($childSubtype === 'HTML') {
                $html .= ($html !== '' ? "\n\n" : '') . $content;
            } elseif ($plain === '') {
                $plain = $content;
            }
        }

        return trim($plain !== '' ? $plain : $html);
    }

    return '';
}

function crmSyncQuotationEmailReplies(
    PDO $pdo,
    int $leadId,
    int $versionId,
    string $customerEmail,
    string $packageTitle,
    ?string $emailSentAt = null
): array {
    $status = [
        'available' => false,
        'synced' => 0,
        'message' => '',
    ];

    if (
        !function_exists('imap_open')
        || !defined('SMTP_USERNAME')
        || !defined('SMTP_PASSWORD')
        || trim((string)SMTP_USERNAME) === ''
        || trim((string)SMTP_PASSWORD) === ''
    ) {
        $status['message'] =
            'Incoming email reply sync requires PHP IMAP and your Gmail App Password.';
        return $status;
    }

    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $status['message'] = 'Customer email is not available.';
        return $status;
    }

    $mailbox =
        defined('IMAP_MAILBOX') && trim((string)IMAP_MAILBOX) !== ''
            ? (string)IMAP_MAILBOX
            : '{imap.gmail.com:993/imap/ssl}INBOX';

    $imap =
        @imap_open(
            $mailbox,
            (string)SMTP_USERNAME,
            (string)SMTP_PASSWORD,
            0,
            1
        );

    if ($imap === false) {
        $status['message'] =
            'Could not connect to the quotation inbox. Enable PHP IMAP / Gmail IMAP.';
        return $status;
    }

    $status['available'] = true;

    try {
        $sinceTime =
            $emailSentAt
                ? strtotime($emailSentAt)
                : false;

        if (!$sinceTime) {
            $sinceTime = strtotime('-90 days');
        }

        $criteria =
            'FROM "'
            . addcslashes($customerEmail, '\\"')
            . '" SINCE "'
            . date('d-M-Y', $sinceTime)
            . '"';

        $uids =
            @imap_search(
                $imap,
                $criteria,
                SE_UID
            );

        if (!is_array($uids)) {
            return $status;
        }

        $threadTag =
            crmQuotationThreadTag($versionId);

        foreach ($uids as $uid) {
            $messageNo = @imap_msgno($imap, (int)$uid);

            if (!$messageNo) {
                continue;
            }

            $overviewList =
                @imap_fetch_overview(
                    $imap,
                    (string)$messageNo,
                    0
                );

            $overview =
                is_array($overviewList) && isset($overviewList[0])
                    ? $overviewList[0]
                    : null;

            if (!$overview) {
                continue;
            }

            $subject =
                crmDecodeImapHeader(
                    (string)($overview->subject ?? '')
                );

            $belongsToVersion =
                stripos($subject, $threadTag) !== false;

            /*
            | Compatibility with quotation emails sent before version tags were added.
            */
            if (
                !$belongsToVersion
                && trim($packageTitle) !== ''
            ) {
                $belongsToVersion =
                    stripos(
                        $subject,
                        trim($packageTitle)
                    ) !== false;
            }

            if (!$belongsToVersion) {
                continue;
            }

            $header =
                @imap_headerinfo(
                    $imap,
                    $messageNo
                );

            $messageId =
                trim(
                    (string)(
                        $overview->message_id
                        ?? ($header->message_id ?? '')
                    )
                );

            if ($messageId === '') {
                $messageId = 'imap-uid-' . $uid;
            }

            $body =
                crmImapMessageText(
                    $imap,
                    $messageNo,
                    @imap_fetchstructure(
                        $imap,
                        $messageNo
                    )
                );

            if ($body === '') {
                $body =
                    '(Customer reply received; the message body could not be decoded.)';
            }

            $timestamp =
                strtotime(
                    (string)($overview->date ?? '')
                );

            crmStoreQuotationMessage(
                $pdo,
                $leadId,
                $versionId,
                'inbound',
                $customerEmail,
                (string)SMTP_USERNAME,
                $subject,
                $body,
                $messageId,
                trim((string)($header->in_reply_to ?? '')),
                $timestamp
                    ? date('Y-m-d H:i:s', $timestamp)
                    : date('Y-m-d H:i:s')
            );

            $status['synced']++;
        }

    } finally {
        @imap_close($imap);
    }

    return $status;
}

function crmQuotationMessages(
    PDO $pdo,
    int $leadId,
    int $versionId
): array {
    $stmt =
        $pdo->prepare("
            SELECT *
            FROM crm_quotation_messages
            WHERE lead_id = ?
              AND version_id = ?
            ORDER BY sent_at ASC, id ASC
        ");

    $stmt->execute([
        $leadId,
        $versionId
    ]);

    return
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        ?: [];
}

$crmFunctionsFile = __DIR__ . '/crm-functions.php';

if (!is_file($crmFunctionsFile)) {
    http_response_code(500);
    exit('CRM setup incomplete: upload crm-functions.php into the same folder as admin-crm.php.');
}

require_once $crmFunctionsFile;

$crmReady = crmTablesReady($pdo);

if (empty($_SESSION['admin_crm_csrf'])) {
    $_SESSION['admin_crm_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['admin_crm_csrf'];
$success = (string)($_SESSION['admin_crm_success'] ?? '');
$error = (string)($_SESSION['admin_crm_error'] ?? '');

unset($_SESSION['admin_crm_success'], $_SESSION['admin_crm_error']);

$statuses = crmAllowedStatuses();
$priorities = crmAllowedPriorities();
$sources = crmAllowedSources();
$activityTypes = crmAllowedActivityTypes();
$currencySymbol = function_exists('getSetting')
    ? getSetting($pdo, 'currency_symbol', '₹')
    : '₹';

// Canonical Destination Master for new CRM enquiries.
// New enquiries should choose a real destination ID instead of manually typing names.
$crmDestinationOptions = $pdo->query("
    SELECT id,name,country
    FROM destinations
    WHERE status='active'
    ORDER BY country,name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];


function crmPost(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}


function crmCustomerPackageUpload(array $file, string $prefix = 'image'): ?string
{
    if (
        empty($file)
        || !isset($file['error'])
        || $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Customer package image upload failed.');
    }

    if ((int)$file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Customer package images must be smaller than 5 MB.');
    }

    if (!is_uploaded_file((string)$file['tmp_name'])) {
        throw new RuntimeException('Invalid customer package image upload.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$mime]) || @getimagesize((string)$file['tmp_name']) === false) {
        throw new RuntimeException('Only valid JPG, PNG and WEBP images are allowed.');
    }

    $directory = __DIR__ . '/uploads/crm-packages/';

    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException('Unable to create the CRM package image directory.');
    }

    $base = pathinfo((string)($file['name'] ?? $prefix), PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9 _-]+/', '', $base);
    $base = trim((string)$base);

    if ($base === '') {
        $base = $prefix;
    }

    $name = $base . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $destination = $directory . $name;

    if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {
        throw new RuntimeException('Unable to save customer package image.');
    }

    return 'uploads/crm-packages/' . $name;
}

function crmCustomerPackageFiles(string $field): array
{
    if (!isset($_FILES[$field]['name'])) {
        return [];
    }

    $upload = $_FILES[$field];

    if (!is_array($upload['name'])) {
        return [$upload];
    }

    $files = [];

    foreach ($upload['name'] as $index => $name) {
        $files[] = [
            'name' => $name,
            'type' => $upload['type'][$index] ?? '',
            'tmp_name' => $upload['tmp_name'][$index] ?? '',
            'error' => $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $upload['size'][$index] ?? 0
        ];
    }

    return $files;
}

function crmCustomerPackageSnapshot(?string $json): array
{
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
}

function crmQuotationItineraryMeaningful(array $days): bool
{
    foreach ($days as $day) {
        if (!is_array($day)) continue;
        if (trim((string)($day['title'] ?? '')) !== '' || trim((string)($day['description'] ?? '')) !== '' || trim((string)($day['image'] ?? '')) !== '') return true;
    }
    return false;
}

function crmQuotationNormalizeItinerary(array $days): array
{
    $normalized=[];
    foreach ($days as $index=>$day) {
        if (!is_array($day)) continue;
        $normalized[]=[
            'day_number'=>max(1,(int)($day['day_number'] ?? ($index+1))),
            'title'=>trim((string)($day['title'] ?? '')),
            'description'=>trim((string)($day['description'] ?? '')),
            'image'=>trim((string)($day['image'] ?? '')),
        ];
    }
    usort($normalized,static fn(array $a,array $b):int=>((int)$a['day_number'])<=>((int)$b['day_number']));
    return array_values($normalized);
}

function crmHydrateQuotationSnapshot(PDO $pdo,array $version,array $snapshot): array
{
    $snapshotDays=is_array($snapshot['itinerary_days'] ?? null)?crmQuotationNormalizeItinerary($snapshot['itinerary_days']):[];
    if (!crmQuotationItineraryMeaningful($snapshotDays)) {
        $legacy=json_decode((string)($version['itinerary'] ?? ''),true);
        if (is_array($legacy)) {
            $legacy=crmQuotationNormalizeItinerary($legacy);
            if (crmQuotationItineraryMeaningful($legacy)) $snapshotDays=$legacy;
        }
    }
    $packageId=max(0,(int)($snapshot['source_package_id'] ?? $version['package_id'] ?? 0));
    if (!crmQuotationItineraryMeaningful($snapshotDays) && $packageId>0 && crmTableExists($pdo,'package_itinerary_days')) {
        $stmt=$pdo->prepare("SELECT day_number,title,description,image FROM package_itinerary_days WHERE package_id=? ORDER BY sort_order ASC,day_number ASC,id ASC");
        $stmt->execute([$packageId]);
        $master=crmQuotationNormalizeItinerary($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        if (crmQuotationItineraryMeaningful($master)) $snapshotDays=$master;
    }
    if ($snapshotDays) $snapshot['itinerary_days']=$snapshotDays;
    if (function_exists('tsHAForQuotation') && function_exists('tsHACustomerSafeRows')) {
        $versionId = max(0,(int)($version['id'] ?? 0));
        if ($versionId > 0) {
            try {
                $snapshot['hotel_allocations'] = tsHACustomerSafeRows(tsHAForQuotation($pdo,$versionId));
            } catch (Throwable $e) {
                error_log('Quotation hotel hydration: '.$e->getMessage());
            }
        }
    }
    if (function_exists('tsTAForQuotation') && function_exists('tsTACustomerSafeRows')) {
        $versionId = max(0,(int)($version['id'] ?? 0));
        if ($versionId > 0) {
            try {
                $snapshot['transport_allocations'] = tsTACustomerSafeRows(tsTAForQuotation($pdo,$versionId));
            } catch (Throwable $e) {
                error_log('Quotation transport hydration: '.$e->getMessage());
            }
        }
    }
    if ($packageId>0 && crmTableExists($pdo,'packages')) {
        $stmt=$pdo->prepare("SELECT * FROM packages WHERE id=? LIMIT 1"); $stmt->execute([$packageId]); $master=$stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $map=['package_code'=>'package_code','package_type'=>'package_type','short_description'=>'short_description','description'=>'description','duration_days'=>'duration_days','duration_nights'=>'duration_nights','currency'=>'currency','main_image'=>'main_image','tour_highlights'=>'tour_highlights','inclusions'=>'inclusions','exclusions'=>'exclusions','terms'=>'terms'];
        foreach($map as $sk=>$mk){$cur=$snapshot[$sk] ?? null;$missing=$cur===null||$cur===''||(in_array($sk,['duration_days','duration_nights'],true)&&(int)$cur<=0);if($missing&&array_key_exists($mk,$master))$snapshot[$sk]=$master[$mk];}
        if (empty($snapshot['gallery_images']) && crmTableExists($pdo,'package_images')) { $stmt=$pdo->prepare("SELECT image FROM package_images WHERE package_id=? ORDER BY sort_order ASC,id ASC");$stmt->execute([$packageId]);$snapshot['gallery_images']=$stmt->fetchAll(PDO::FETCH_COLUMN) ?: []; }
    }
    return $snapshot;
}

/**
 * Freeze customer-safe Trip service requirements into each quotation version.
 * Hotel and transport allocations remain separate version-owned records, while
 * the remaining service requirements are snapshotted here so every quotation
 * version can show transfers/sightseeing/activities/meals/flights/etc.
 */
function crmQuotationServiceSnapshot(PDO $pdo, ?array $trip): array
{
    if (!$trip || empty($trip['id']) || !function_exists('crmTripServiceRequirements')) {
        return [];
    }

    try {
        $rows = crmTripServiceRequirements($pdo, (int)$trip['id']);
    } catch (Throwable $e) {
        error_log('Quotation service snapshot: ' . $e->getMessage());
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $type = strtolower(trim((string)($row['service_type'] ?? 'other')));
        if ($type === '') $type = 'other';
        $status = strtolower(trim((string)($row['status'] ?? 'rate_required')));
        if ($status === 'cancelled') continue;

        $out[] = [
            'service_requirement_id' => (int)($row['id'] ?? 0),
            'service_type' => $type,
            'service_label' => function_exists('crmServiceRequirementTypeLabel')
                ? crmServiceRequirementTypeLabel($type)
                : ucwords(str_replace('_', ' ', $type)),
            'title' => trim((string)($row['title'] ?? '')),
            'destination_id' => !empty($row['destination_id']) ? (int)$row['destination_id'] : null,
            'destination_name' => trim((string)($row['destination_name'] ?? '')),
            'service_date_from' => trim((string)($row['service_date_from'] ?? '')),
            'service_date_to' => trim((string)($row['service_date_to'] ?? '')),
            'quantity' => (float)($row['quantity'] ?? 1),
            'specifications' => trim((string)($row['specifications'] ?? '')),
            'status' => $status,
        ];
    }

    return $out;
}

function crmSelectedSupplierCosting(PDO $pdo, int $enquiryId): array
{
    $result = [
        'rows' => [],
        'mode' => 'package_master',
        'mode_label' => 'Package Master',
        'currency' => '',
        'net_amount' => 0.0,
        'tax_amount' => 0.0,
        'other_amount' => 0.0,
        'total_supplier_cost' => 0.0,
        'supplier_names' => [],
        'quote_count' => 0,
        'valid' => true,
        'error' => '',
    ];

    if (
        $enquiryId <= 0
        || !crmTableExists($pdo, 'supplier_requests')
        || !crmTableExists($pdo, 'supplier_quotes')
    ) {
        return $result;
    }

    $modeSelect =
        crmColumnExists($pdo, 'supplier_quotes', 'costing_mode')
            ? 'q.costing_mode'
            : 'NULL AS costing_mode';

    $tripSelect =
        crmColumnExists($pdo, 'supplier_requests', 'trip_id')
            ? 'r.trip_id'
            : 'NULL AS trip_id';

    $serviceRequirementSelect =
        crmColumnExists($pdo, 'supplier_requests', 'service_requirement_id')
            ? 'r.service_requirement_id'
            : 'NULL AS service_requirement_id';

    $stmt = $pdo->prepare("
        SELECT
            r.id AS request_id,
            r.request_code,
            r.request_type,
            r.supplier_id,
            {$tripSelect},
            {$serviceRequirementSelect},
            q.id AS quote_id,
            q.version_number,
            q.currency,
            q.net_amount,
            q.upgrade_amount,
            q.tax_amount,
            q.total_supplier_cost,
            {$modeSelect},
            s.company_name
        FROM supplier_requests r
        JOIN supplier_quotes q
            ON q.id = r.selected_quote_id
        JOIN suppliers s
            ON s.id = r.supplier_id
        WHERE r.enquiry_id = ?
          AND r.status = 'selected'
          AND q.status = 'selected'
        ORDER BY r.updated_at ASC, r.id ASC
    ");
    $stmt->execute([$enquiryId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$rows) {
        return $result;
    }

    $itemsByQuote = [];

    if (crmTableExists($pdo, 'supplier_quote_items')) {
        $quoteIds = array_values(
            array_filter(
                array_map(
                    static fn(array $row): int => (int)($row['quote_id'] ?? 0),
                    $rows
                )
            )
        );

        if ($quoteIds) {
            $placeholders = implode(',', array_fill(0, count($quoteIds), '?'));

            $itemStmt = $pdo->prepare("
                SELECT *
                FROM supplier_quote_items
                WHERE supplier_quote_id IN ({$placeholders})
                ORDER BY supplier_quote_id, sort_order, id
            ");
            $itemStmt->execute($quoteIds);

            foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
                $itemsByQuote[(int)$item['supplier_quote_id']][] = $item;
            }
        }
    }

    $currencies = [];
    $supplierNames = [];
    $baseQuoteCount = 0;
    $serviceWiseCount = 0;
    $hasHybrid = false;

    foreach ($rows as &$row) {
        $storedMode = strtolower(trim((string)($row['costing_mode'] ?? '')));

        if (!in_array($storedMode, ['complete_package','service_wise','hybrid'], true)) {
            $storedMode =
                (string)($row['request_type'] ?? '') === 'service_quote'
                    ? 'service_wise'
                    : 'complete_package';
        }

        $row['costing_mode'] = $storedMode;
        $row['items'] = $itemsByQuote[(int)$row['quote_id']] ?? [];

        if ($storedMode === 'service_wise') {
            $serviceWiseCount++;
        } else {
            $baseQuoteCount++;
        }

        if ($storedMode === 'hybrid') {
            $hasHybrid = true;
        }

        $currency = strtoupper(trim((string)($row['currency'] ?? '')));
        if ($currency !== '') {
            $currencies[$currency] = true;
        }

        $supplierName = trim((string)($row['company_name'] ?? ''));
        if ($supplierName !== '') {
            $supplierNames[$supplierName] = true;
        }

        $result['net_amount'] += max(0, (float)($row['net_amount'] ?? 0));
        $result['tax_amount'] += max(0, (float)($row['tax_amount'] ?? 0));
        $result['other_amount'] += max(0, (float)($row['upgrade_amount'] ?? 0));
        $result['total_supplier_cost'] += max(0, (float)($row['total_supplier_cost'] ?? 0));
    }
    unset($row);

    $result['rows'] = array_values($rows);
    $result['supplier_names'] = array_keys($supplierNames);
    $result['quote_count'] = count($rows);

    if (count($currencies) > 1) {
        $result['valid'] = false;
        $result['error'] =
            'Selected supplier quotes use different currencies. Keep the costing in one currency before creating the customer quotation.';
    } else {
        $result['currency'] = (string)(array_key_first($currencies) ?? '');
    }

    /*
    |--------------------------------------------------------------------------
    | Prevent accidental double-counting of two complete package quotes.
    |--------------------------------------------------------------------------
    |
    | One full-package supplier base can be combined with any number of
    | service-wise additions. Two full-package bases would normally represent
    | alternative suppliers for the same land package, not additive costs.
    |
    */
    if ($baseQuoteCount > 1) {
        $result['valid'] = false;
        $result['error'] =
            'More than one complete/hybrid supplier package quote is selected. Keep only one full package supplier quote, then add separate service-wise quotes if required.';
    }

    if ($hasHybrid || ($baseQuoteCount === 1 && $serviceWiseCount > 0)) {
        $result['mode'] = 'hybrid';
        $result['mode_label'] = 'Hybrid Costing';
    } elseif ($baseQuoteCount === 1) {
        $result['mode'] = 'complete_package';
        $result['mode_label'] = 'Complete Package Rate';
    } else {
        $result['mode'] = 'service_wise';
        $result['mode_label'] = 'Service-wise Costing';
    }

    return $result;
}


function crmCustomerPackageArray(string $key): array
{
    $value = $_POST[$key] ?? [];
    return is_array($value) ? $value : [];
}


/*
|--------------------------------------------------------------------------
| CRM PROFESSIONAL MEDIA + PEXELS HELPERS
|--------------------------------------------------------------------------
*/
function crmImageUrl(string $image): string
{
    $image = trim($image);

    if ($image === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $image)) {
        return $image;
    }

    /*
    |--------------------------------------------------------------------------
    | SAFE PUBLIC URL FOR LOCAL IMAGES
    |--------------------------------------------------------------------------
    |
    | Pexels imports keep readable filenames and those filenames can contain
    | spaces. Encode each path segment only for the browser URL while keeping
    | the real stored filesystem/database path unchanged.
    |
    */

    $segments = array_map(
        static fn(string $segment): string => rawurlencode($segment),
        explode('/', ltrim($image, '/'))
    );

    return BASE_URL . implode('/', $segments);
}

function crmMediaLibrary(): array
{
    $items = [];
    $sources = [
        ['dir' => __DIR__ . '/uploads/crm-packages/', 'prefix' => 'uploads/crm-packages/'],
        ['dir' => __DIR__ . '/uploads/packages/', 'prefix' => 'uploads/packages/'],
        ['dir' => __DIR__ . '/uploads/itinerary/', 'prefix' => 'uploads/itinerary/'],
    ];

    foreach ($sources as $source) {
        if (!is_dir($source['dir'])) continue;
        foreach (scandir($source['dir']) ?: [] as $name) {
            $file = $source['dir'] . $name;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!is_file($file) || !in_array($ext, ['jpg','jpeg','png','webp'], true)) continue;
            $items[] = [
                'name' => $name,
                'image' => $source['prefix'] . $name,
                'url' => crmImageUrl($source['prefix'] . $name),
                'mtime' => (int)@filemtime($file),
            ];
        }
    }

    usort($items, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    return $items;
}

function crmValidMediaPath(string $path): ?string
{
    $path = trim($path);
    if ($path === '') return null;

    /*
    | Unsplash API guideline compliance:
    | Keep selected Unsplash images as the hotlinked images.unsplash.com URL
    | returned by the API. The access key is never exposed to the browser.
    */
    if (preg_match('#^https://images\.unsplash\.com/#i', $path)) {
        return filter_var($path, FILTER_VALIDATE_URL) ? $path : null;
    }

    $roots = [
        'uploads/crm-packages/' => __DIR__ . '/uploads/crm-packages/',
        'uploads/packages/' => __DIR__ . '/uploads/packages/',
        'uploads/itinerary/' => __DIR__ . '/uploads/itinerary/',
    ];

    foreach ($roots as $prefix => $root) {
        if (strpos($path, $prefix) !== 0) continue;
        $base = realpath($root);
        $file = realpath(__DIR__ . '/' . $path);
        if ($base && $file && is_file($file) && strpos($file, $base . DIRECTORY_SEPARATOR) === 0) {
            return $path;
        }
    }
    return null;
}

function crmPexelsApiKey(): string
{
    return defined('PEXELS_API_KEY') ? trim((string)PEXELS_API_KEY) : '';
}

function crmPexelsSearch(string $query, int $page = 1): array
{
    $apiKey = crmPexelsApiKey();
    if ($apiKey === '' || $apiKey === 'YOUR_PEXELS_API_KEY_HERE') {
        throw new RuntimeException('Pexels API key is not configured in config.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for free image search.');
    }

    $query = trim($query);
    if ($query === '') throw new RuntimeException('Enter an image search keyword.');
    $page = max(1, $page);
    $perPage = defined('PEXELS_API_PER_PAGE') ? max(1, min(30, (int)PEXELS_API_PER_PAGE)) : 18;
    $base = defined('PEXELS_API_BASE_URL') ? rtrim((string)PEXELS_API_BASE_URL, '/') : 'https://api.pexels.com/v1';
    $url = $base . '/search?query=' . rawurlencode($query) . '&orientation=landscape&size=large&page=' . $page . '&per_page=' . $perPage;

    $request = tsPexelsHttpRequest(
        $url,
        ['Authorization: ' . $apiKey, 'Accept: application/json', 'Connection: close'],
        null,
        45
    );
    $body = $request['body'];
    $status = (int)$request['status'];
    $data = json_decode((string)$body, true);
    if ($status !== 200 || !is_array($data)) {
        throw new RuntimeException(is_array($data) && !empty($data['error']) ? (string)$data['error'] : 'Pexels search failed.');
    }

    $photos = [];
    foreach (($data['photos'] ?? []) as $photo) {
        if (!is_array($photo)) continue;
        $src = is_array($photo['src'] ?? null) ? $photo['src'] : [];
        $preview = trim((string)($src['large'] ?? $src['landscape'] ?? $src['medium'] ?? ''));
        $download = trim((string)($src['large2x'] ?? $src['large'] ?? $src['original'] ?? ''));
        if ($preview === '' || $download === '' || empty($photo['id'])) continue;
        $photos[] = [
            'provider' => 'pexels',
            'provider_label' => 'Pexels',
            'id' => (string)$photo['id'],
            'preview' => $preview,
            'download' => $download,
            'photo_url' => (string)($photo['url'] ?? ''),
            'photographer' => (string)($photo['photographer'] ?? ''),
            'photographer_url' => (string)($photo['photographer_url'] ?? ''),
            'alt' => (string)($photo['alt'] ?? $query),
        ];
    }

    return [
        'photos' => $photos,
        'page' => (int)($data['page'] ?? $page),
        'total_results' => (int)($data['total_results'] ?? count($photos)),
        'next_page' => !empty($data['next_page']),
        'prev_page' => !empty($data['prev_page']),
    ];
}


function crmUnsplashApiKey(): string
{
    return defined('UNSPLASH_ACCESS_KEY')
        ? trim((string)UNSPLASH_ACCESS_KEY)
        : '';
}

function crmUnsplashUtm(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . 'utm_source=travscope&utm_medium=referral';
}

function crmUnsplashSearch(string $query, int $page = 1): array
{
    $apiKey = crmUnsplashApiKey();
    if ($apiKey === '') {
        throw new RuntimeException('Unsplash Access Key is not configured in config.php.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for free image search.');
    }

    $query = trim($query);
    if ($query === '') throw new RuntimeException('Enter an image search keyword.');
    $page = max(1, $page);
    $perPage = defined('UNSPLASH_API_PER_PAGE')
        ? max(1, min(30, (int)UNSPLASH_API_PER_PAGE))
        : 24;
    $base = defined('UNSPLASH_API_BASE_URL')
        ? rtrim((string)UNSPLASH_API_BASE_URL, '/')
        : 'https://api.unsplash.com';

    $url = $base
        . '/search/photos?query=' . rawurlencode($query)
        . '&orientation=landscape'
        . '&content_filter=high'
        . '&order_by=relevant'
        . '&page=' . $page
        . '&per_page=' . $perPage;

    $request = tsPexelsHttpRequest(
        $url,
        [
            'Authorization: Client-ID ' . $apiKey,
            'Accept-Version: v1',
            'Accept: application/json',
            'Connection: close',
        ],
        null,
        defined('UNSPLASH_API_TIMEOUT')
            ? max(20, (int)UNSPLASH_API_TIMEOUT)
            : 30
    );

    $status = (int)$request['status'];
    $data = json_decode((string)$request['body'], true);
    if ($status !== 200 || !is_array($data)) {
        $message = is_array($data)
            ? trim((string)($data['errors'][0] ?? $data['error'] ?? ''))
            : '';
        throw new RuntimeException($message !== '' ? $message : 'Unsplash search failed.');
    }

    $photos = [];
    foreach (($data['results'] ?? []) as $photo) {
        if (!is_array($photo)) continue;
        $urls = is_array($photo['urls'] ?? null) ? $photo['urls'] : [];
        $links = is_array($photo['links'] ?? null) ? $photo['links'] : [];
        $user = is_array($photo['user'] ?? null) ? $photo['user'] : [];
        $userLinks = is_array($user['links'] ?? null) ? $user['links'] : [];

        $preview = trim((string)($urls['small'] ?? $urls['regular'] ?? ''));
        $hotlink = trim((string)($urls['regular'] ?? $urls['full'] ?? $urls['raw'] ?? ''));
        $downloadLocation = trim((string)($links['download_location'] ?? ''));
        $photoPage = crmUnsplashUtm((string)($links['html'] ?? ''));
        $photographerUrl = crmUnsplashUtm((string)($userLinks['html'] ?? ''));
        $photoId = trim((string)($photo['id'] ?? ''));
        if ($preview === '' || $hotlink === '' || $photoId === '') continue;

        $photos[] = [
            'provider' => 'unsplash',
            'provider_label' => 'Unsplash',
            'id' => $photoId,
            'preview' => $preview,
            'download' => $hotlink,
            'photo_url' => $photoPage,
            'photographer' => trim((string)($user['name'] ?? 'Unsplash contributor')),
            'photographer_url' => $photographerUrl,
            'alt' => trim((string)($photo['alt_description'] ?? $photo['description'] ?? $query)),
            'download_location' => $downloadLocation,
            'width' => (int)($photo['width'] ?? 0),
            'height' => (int)($photo['height'] ?? 0),
        ];
    }

    return [
        'photos' => $photos,
        'page' => $page,
        'total_results' => (int)($data['total'] ?? count($photos)),
        'next_page' => $page < (int)($data['total_pages'] ?? $page),
        'prev_page' => $page > 1,
    ];
}

function crmCombinedFreePhotoSearch(string $query, int $page = 1): array
{
    $providerResults = [];
    $providerStatus = [];
    $providerMeta = [
        'pexels' => ['total_results' => 0, 'next_page' => false, 'prev_page' => false],
        'unsplash' => ['total_results' => 0, 'next_page' => false, 'prev_page' => false],
    ];

    try {
        $pexels = crmPexelsSearch($query, $page);
        $providerMeta['pexels'] = [
            'total_results' => (int)($pexels['total_results'] ?? 0),
            'next_page' => !empty($pexels['next_page']),
            'prev_page' => !empty($pexels['prev_page']),
        ];
        $providerResults['pexels'] = array_values((array)($pexels['photos'] ?? []));
        $providerStatus['pexels'] = [
            'ok' => true,
            'count' => count($providerResults['pexels']),
            'message' => count($providerResults['pexels'])
                ? 'Pexels results loaded.'
                : 'Pexels returned no matching photos.',
        ];
    } catch (Throwable $e) {
        $providerResults['pexels'] = [];
        $providerStatus['pexels'] = [
            'ok' => false,
            'count' => 0,
            'message' => 'Pexels unavailable: ' . $e->getMessage(),
        ];
    }

    try {
        $unsplash = crmUnsplashSearch($query, $page);
        $providerMeta['unsplash'] = [
            'total_results' => (int)($unsplash['total_results'] ?? 0),
            'next_page' => !empty($unsplash['next_page']),
            'prev_page' => !empty($unsplash['prev_page']),
        ];
        $providerResults['unsplash'] = array_values((array)($unsplash['photos'] ?? []));
        $providerStatus['unsplash'] = [
            'ok' => true,
            'count' => count($providerResults['unsplash']),
            'message' => count($providerResults['unsplash'])
                ? 'Unsplash results loaded.'
                : 'Unsplash returned no matching photos.',
        ];
    } catch (Throwable $e) {
        $providerResults['unsplash'] = [];
        $providerStatus['unsplash'] = [
            'ok' => false,
            'count' => 0,
            'message' => 'Unsplash unavailable: ' . $e->getMessage(),
        ];
    }

    $photos = [];
    $max = max(count($providerResults['pexels']), count($providerResults['unsplash']));
    for ($index = 0; $index < $max; $index++) {
        if (isset($providerResults['pexels'][$index])) {
            $photos[] = $providerResults['pexels'][$index];
        }
        if (isset($providerResults['unsplash'][$index])) {
            $photos[] = $providerResults['unsplash'][$index];
        }
    }

    if (!$photos && !$providerStatus['pexels']['ok'] && !$providerStatus['unsplash']['ok']) {
        throw new RuntimeException(
            'Both free-photo providers are currently unavailable. '
            . $providerStatus['pexels']['message'] . ' '
            . $providerStatus['unsplash']['message']
        );
    }

    /*
    | Pagination belongs to the combined result set. V24.29 returned only the
    | current page number here, so the CRM JavaScript never received
    | next_page/prev_page and kept the Next button disabled even though Pexels
    | or Unsplash had more pages. Keep the providers independent and expose a
    | combined navigation state: Next is available while EITHER provider has a
    | next page; Previous is available while either provider has a previous
    | page (or we are beyond page 1).
    */
    $combinedNext = !empty($providerMeta['pexels']['next_page'])
        || !empty($providerMeta['unsplash']['next_page']);
    $combinedPrev = max(1, $page) > 1
        || !empty($providerMeta['pexels']['prev_page'])
        || !empty($providerMeta['unsplash']['prev_page']);
    $combinedTotal = (int)$providerMeta['pexels']['total_results']
        + (int)$providerMeta['unsplash']['total_results'];

    return [
        'photos' => $photos,
        'provider_status' => $providerStatus,
        'provider_meta' => $providerMeta,
        'counts' => [
            'all' => count($photos),
            'pexels' => count($providerResults['pexels']),
            'unsplash' => count($providerResults['unsplash']),
        ],
        'page' => max(1, $page),
        'total_results' => $combinedTotal > 0 ? $combinedTotal : count($photos),
        'next_page' => $combinedNext,
        'prev_page' => $combinedPrev,
    ];
}

function crmUnsplashUse(array $photo): array
{
    $imageUrl = trim((string)($photo['download'] ?? ''));
    $downloadLocation = trim((string)($photo['download_location'] ?? ''));
    $photoId = preg_replace('/[^0-9A-Za-z_-]/', '', (string)($photo['id'] ?? ''));

    if ($imageUrl === '' || $photoId === '' || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Invalid Unsplash image information.');
    }
    $imageHost = strtolower((string)parse_url($imageUrl, PHP_URL_HOST));
    if ($imageHost !== 'images.unsplash.com') {
        throw new RuntimeException('Only official Unsplash image URLs are allowed.');
    }

    /*
    | Unsplash requires the download tracking endpoint to be called when a
    | user chooses an API photo for use. Failure to record the event should
    | not block the user from selecting the image, so it is logged only.
    */
    if ($downloadLocation !== '' && filter_var($downloadLocation, FILTER_VALIDATE_URL)) {
        $trackHost = strtolower((string)parse_url($downloadLocation, PHP_URL_HOST));
        $trackPath = (string)parse_url($downloadLocation, PHP_URL_PATH);
        if ($trackHost === 'api.unsplash.com' && str_contains($trackPath, '/photos/') && str_ends_with($trackPath, '/download')) {
            try {
                tsPexelsHttpRequest(
                    $downloadLocation,
                    [
                        'Authorization: Client-ID ' . crmUnsplashApiKey(),
                        'Accept-Version: v1',
                        'Accept: application/json',
                        'Connection: close',
                    ],
                    null,
                    20
                );
            } catch (Throwable $e) {
                error_log('Unsplash download tracking failed: ' . $e->getMessage());
            }
        }
    }

    return [
        'image' => $imageUrl,
        'url' => $imageUrl,
        'name' => 'Unsplash-' . $photoId,
        'provider' => 'Unsplash',
        'photographer' => trim((string)($photo['photographer'] ?? '')),
        'photographer_url' => trim((string)($photo['photographer_url'] ?? '')),
        'photo_url' => trim((string)($photo['photo_url'] ?? '')),
    ];
}

function crmPexelsImport(array $photo): array
{
    $imageUrl = trim((string)($photo['download'] ?? ''));
    $photoId = preg_replace('/[^0-9A-Za-z_-]/', '', (string)($photo['id'] ?? ''));
    $alt = trim((string)($photo['alt'] ?? 'Travel Photo'));
    if ($imageUrl === '' || $photoId === '' || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Invalid Pexels image information.');
    }
    $host = strtolower((string)parse_url($imageUrl, PHP_URL_HOST));
    if ($host !== 'images.pexels.com') throw new RuntimeException('Only Pexels image URLs are allowed.');
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required to import images.');

    $dir = __DIR__ . '/uploads/crm-packages/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Unable to create CRM image directory.');

    /*
    |--------------------------------------------------------------------------
    | WEB-SAFE PEXELS FILENAME
    |--------------------------------------------------------------------------
    |
    | Use a simple ASCII filename for CRM quotation images so Apache/Nginx,
    | browser previews and PDF/print views can all load the image reliably.
    |
    */

    $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $alt);
    $base = trim((string)$base, '-_');

    if ($base === '') {
        $base = 'travel-photo';
    }

    $base = substr($base, 0, 70);

    $name =
        $base
        . '-pexels-'
        . $photoId
        . '.jpg';
    $path = $dir . $name;
    $relative = 'uploads/crm-packages/' . $name;

    if (!is_file($path)) {
        $tmp = tempnam(sys_get_temp_dir(), 'crm_pexels_');
        if ($tmp === false) throw new RuntimeException('Unable to prepare image download.');
        $request = tsPexelsHttpRequest($imageUrl, ['Accept: image/*', 'Connection: close'], $tmp, 60);
        $status = (int)$request['status'];
        $type = strtolower((string)$request['content_type']);
        if ($status < 200 || $status >= 300 || !str_starts_with($type, 'image/') || !is_file($tmp) || @getimagesize($tmp) === false) {
            @unlink($tmp);
            throw new RuntimeException('Unable to download the selected free image.');
        }
        if (!@rename($tmp, $path)) {
            if (!@copy($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Unable to save the selected image.'); }
            @unlink($tmp);
        }
    }

    return ['image'=>$relative, 'url'=>crmImageUrl($relative), 'name'=>$name];
}

/* AJAX endpoint used by the CRM image picker. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['crm_free_photo_search','crm_pexels_search','crm_pexels_import','crm_unsplash_use'], true)) {
    header('Content-Type: application/json; charset=UTF-8');
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Security verification failed. Refresh and try again.']);
        exit;
    }
    try {
        $mediaAction = (string)($_POST['action'] ?? '');
        if ($mediaAction === 'crm_free_photo_search') {
            echo json_encode([
                'success'=>true,
                'data'=>crmCombinedFreePhotoSearch(
                    (string)($_POST['query'] ?? ''),
                    (int)($_POST['page'] ?? 1)
                )
            ], JSON_UNESCAPED_SLASHES);
        } elseif ($mediaAction === 'crm_pexels_search') {
            /* Backward-compatible endpoint retained for older cached JS. */
            echo json_encode(['success'=>true,'data'=>crmPexelsSearch((string)($_POST['query'] ?? ''), (int)($_POST['page'] ?? 1))], JSON_UNESCAPED_SLASHES);
        } elseif ($mediaAction === 'crm_unsplash_use') {
            $photo = [
                'id'=>(string)($_POST['photo_id'] ?? ''),
                'download'=>(string)($_POST['image_url'] ?? ''),
                'download_location'=>(string)($_POST['download_location'] ?? ''),
                'photographer'=>(string)($_POST['photographer'] ?? ''),
                'photographer_url'=>(string)($_POST['photographer_url'] ?? ''),
                'photo_url'=>(string)($_POST['photo_url'] ?? ''),
            ];
            echo json_encode(['success'=>true,'data'=>crmUnsplashUse($photo)], JSON_UNESCAPED_SLASHES);
        } else {
            $photo = [
                'id'=>(string)($_POST['photo_id'] ?? ''),
                'download'=>(string)($_POST['image_url'] ?? ''),
                'alt'=>(string)($_POST['alt'] ?? ''),
            ];
            echo json_encode(['success'=>true,'data'=>crmPexelsImport($photo)], JSON_UNESCAPED_SLASHES);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

$crmMediaLibrary = crmMediaLibrary();
$crmSupplierOptions = $crmSupplierOptions ?? [];



/*
|--------------------------------------------------------------------------
| PROFESSIONAL QUOTATION MESSAGE + PDF HELPERS
|--------------------------------------------------------------------------
*/

function crmQuotationPdfEscape(string $text): string
{
    $text = str_replace(
        ["\\", "(", ")", "\r", "\n", "\t"],
        ["\\\\", "\\(", "\\)", "", " ", " "],
        $text
    );

    $text = str_replace(
        ['₹', '–', '—', '•', '✓', '→'],
        ['INR ', '-', '-', '-', '', '->'],
        $text
    );

    return preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;
}

function crmQuotationPdfWrap(string $text, int $width = 92): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

    if ($text === '') {
        return [''];
    }

    return explode(
        "\n",
        wordwrap(
            $text,
            max(30, $width),
            "\n",
            true
        )
    );
}

function crmQuotationPdfLines(
    array $version,
    array $lead,
    array $snapshot,
    string $siteName,
    string $currencySymbol
): array {
    $lines = [];

    $add = static function (array &$lines, string $text, string $type = 'body'): void {
        foreach (crmQuotationPdfWrap($text) as $wrapped) {
            $lines[] = ['text' => $wrapped, 'type' => $type];
        }
    };

    $add($lines, strtoupper($siteName), 'brand');
    $add($lines, 'TRAVEL QUOTATION - VERSION ' . (int)($version['version_number'] ?? 0), 'title');
    $add($lines, (string)($version['package_title'] ?? 'Travel Package'), 'package');

    if (!empty($snapshot['short_description'])) {
        $add($lines, (string)$snapshot['short_description'], 'subtle');
    }

    $lines[] = ['text' => '', 'type' => 'space'];

    $add($lines, 'CUSTOMER DETAILS', 'section');
    $add($lines, 'Customer: ' . (string)($lead['customer_name'] ?? '-'));
    $add($lines, 'Mobile: ' . (string)($lead['customer_mobile'] ?? '-'));
    $add($lines, 'Email: ' . (string)($lead['customer_email'] ?? '-'));
    $add($lines, 'Destination: ' . (string)($lead['customer_destination'] ?? '-'));

    $lines[] = ['text' => '', 'type' => 'space'];

    $add($lines, 'QUOTATION SUMMARY', 'section');
    $add($lines, 'Travel Date: ' . crmDate((string)($version['travel_date'] ?? '')));
    $add(
        $lines,
        'Travellers: ' .
        (int)($version['adults'] ?? 0) .
        ' Adult(s) + ' .
        (int)($version['children'] ?? 0) .
        ' Child(ren)'
    );
    $add(
        $lines,
        'Duration: ' .
        (int)($snapshot['duration_days'] ?? 0) .
        ' Day(s) / ' .
        (int)($snapshot['duration_nights'] ?? 0) .
        ' Night(s)'
    );

    if (!empty($version['base_price'])) {
        $add($lines, 'Base Price: ' . crmMoney((float)$version['base_price'], $currencySymbol));
    }

    if (!empty($version['discount_amount'])) {
        $add($lines, 'Discount: ' . crmMoney((float)$version['discount_amount'], $currencySymbol));
    }

    $add(
        $lines,
        'FINAL QUOTE: ' . crmMoney((float)($version['final_price'] ?? 0), $currencySymbol),
        'price'
    );

    if (!empty($snapshot['description'])) {
        $lines[] = ['text' => '', 'type' => 'space'];
        $add($lines, 'PACKAGE OVERVIEW', 'section');

        foreach (preg_split('/\R+/', (string)$snapshot['description']) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $add($lines, trim($paragraph));
            }
        }
    }

    $prices = is_array($snapshot['date_prices'] ?? null) ? $snapshot['date_prices'] : [];

    if ($prices) {
        $lines[] = ['text' => '', 'type' => 'space'];
        $add($lines, 'DATE-WISE HOTEL PRICING', 'section');

        foreach ($prices as $index => $row) {
            $parts = [
                'Period ' . ($index + 1) . ':',
                crmDate((string)($row['valid_from'] ?? '')) .
                ' to ' .
                crmDate((string)($row['valid_to'] ?? '')),
            ];

            if (!empty($row['price_3_star'])) {
                $parts[] = '3 Star ' . crmMoney((float)$row['price_3_star'], $currencySymbol);
            }

            if (!empty($row['price_4_star'])) {
                $parts[] = '4 Star ' . crmMoney((float)$row['price_4_star'], $currencySymbol);
            }

            if (!empty($row['price_5_star'])) {
                $parts[] = '5 Star ' . crmMoney((float)$row['price_5_star'], $currencySymbol);
            }

            $add($lines, implode(' | ', $parts));
        }
    }

    $hotelAllocations = is_array($snapshot['hotel_allocations'] ?? null) ? $snapshot['hotel_allocations'] : [];

    if ($hotelAllocations) {
        $lines[] = ['text' => '', 'type' => 'space'];
        $add($lines, 'PROPOSED ACCOMMODATION', 'section');
        foreach ($hotelAllocations as $hotelRow) {
            if (!is_array($hotelRow)) continue;
            $parts = [];
            $destination = trim((string)($hotelRow['destination_name'] ?? ''));
            if ($destination !== '') $parts[] = $destination;
            $parts[] = max(1,(int)($hotelRow['nights'] ?? 1)) . ' Night(s)';
            if (!empty($hotelRow['hotel_category'])) $parts[] = (int)$hotelRow['hotel_category'] . ' Star';
            $actual = trim((string)($hotelRow['hotel_name'] ?? ''));
            $alts = is_array($hotelRow['alternatives'] ?? null) ? $hotelRow['alternatives'] : [];
            if ($actual !== '') {
                $parts[] = $actual;
            } elseif ($alts) {
                $parts[] = implode(' / ', array_slice(array_map('strval',$alts),0,4)) . ' or similar';
            } elseif (($hotelRow['accommodation_type'] ?? '') === 'houseboat') {
                $parts[] = 'Houseboat as per selected category';
            }
            if (!empty($hotelRow['room_type'])) $parts[] = 'Room: ' . (string)$hotelRow['room_type'];
            if (!empty($hotelRow['meal_plan'])) $parts[] = 'Meal: ' . (string)$hotelRow['meal_plan'];
            $add($lines, implode(' | ', $parts));
        }
    }

    $transportAllocations = is_array($snapshot['transport_allocations'] ?? null) ? $snapshot['transport_allocations'] : [];
    if ($transportAllocations) {
        $lines[] = ['text' => '', 'type' => 'space'];
        $add($lines, 'PROPOSED TRANSPORT', 'section');
        foreach ($transportAllocations as $transportRow) {
            if (!is_array($transportRow)) continue;
            $parts = [];
            $route = trim((string)($transportRow['route_label'] ?? ''));
            if ($route !== '') $parts[] = $route;
            $destination = trim((string)($transportRow['destination_name'] ?? ''));
            if ($destination !== '') $parts[] = $destination;
            $vehicle = trim((string)($transportRow['vehicle_type'] ?? ''));
            $alts = is_array($transportRow['alternatives'] ?? null) ? $transportRow['alternatives'] : [];
            if ($vehicle !== '') $parts[] = $vehicle;
            elseif ($alts) $parts[] = implode(' / ', array_slice(array_map('strval',$alts),0,4)) . ' or similar';
            else $parts[] = 'Vehicle as per itinerary / pax';
            if (!empty($transportRow['ac_basis']) && $transportRow['ac_basis'] !== 'unspecified') $parts[] = strtoupper((string)$transportRow['ac_basis']);
            if (!empty($transportRow['pax_total'])) $parts[] = (int)$transportRow['pax_total'] . ' Traveller(s)';
            $add($lines, implode(' | ', $parts));
        }
    }

    $quotationServices = is_array($snapshot['quotation_services'] ?? null) ? $snapshot['quotation_services'] : [];
    $otherQuotationServices = array_values(array_filter($quotationServices, static function ($row): bool {
        if (!is_array($row)) return false;
        $type = strtolower((string)($row['service_type'] ?? 'other'));
        return !in_array($type, ['hotel','transport'], true)
            && strtolower((string)($row['status'] ?? '')) !== 'cancelled';
    }));
    if ($otherQuotationServices) {
        $lines[] = ['text' => '', 'type' => 'space'];
        $add($lines, 'OTHER INCLUDED SERVICES', 'section');
        foreach ($otherQuotationServices as $serviceRow) {
            $parts = [];
            $title = trim((string)($serviceRow['title'] ?? ''));
            $label = trim((string)($serviceRow['service_label'] ?? 'Service'));
            $parts[] = $title !== '' ? $title : $label;
            if ($title !== '' && $label !== '') $parts[] = $label;
            $destination = trim((string)($serviceRow['destination_name'] ?? ''));
            if ($destination !== '') $parts[] = $destination;
            $spec = trim((string)($serviceRow['specifications'] ?? ''));
            if ($spec !== '') $parts[] = $spec;
            $add($lines, implode(' | ', $parts));
        }
    }

    $flightSuggestions = is_array($snapshot['flight_suggestions'] ?? null) ? $snapshot['flight_suggestions'] : [];
    if ($flightSuggestions) {
        $lines[] = ['text' => '', 'type' => 'space'];
        $add($lines, 'SUGGESTED FLIGHT OPTIONS', 'section');
        foreach ($flightSuggestions as $flightRow) {
            if (!is_array($flightRow)) continue;
            $parts=[];
            $parts[]='Sector '.(int)($flightRow['sector_order'] ?? 1).': '.trim((string)($flightRow['origin_code'] ?? '')).' to '.trim((string)($flightRow['destination_code'] ?? ''));
            $airline=trim((string)($flightRow['airline_name'] ?? '')); $flightNo=trim((string)($flightRow['flight_number'] ?? ''));
            if ($airline!=='' || $flightNo!=='') $parts[]=trim($airline.' '.$flightNo);
            if (!empty($flightRow['departure_at'])) $parts[]='Departure '.crmDate((string)$flightRow['departure_at']).' '.date('H:i',strtotime((string)$flightRow['departure_at']));
            if (!empty($flightRow['arrival_at'])) $parts[]='Arrival '.crmDate((string)$flightRow['arrival_at']).' '.date('H:i',strtotime((string)$flightRow['arrival_at']));
            $parts[]=(int)($flightRow['stops'] ?? 0)===0?'Direct':(int)$flightRow['stops'].' Stop(s)';
            if (!empty($flightRow['cabin_class'])) $parts[]=ucwords(strtolower(str_replace('_',' ',(string)$flightRow['cabin_class'])));
            if (!empty($flightRow['baggage'])) $parts[]='Baggage '.(string)$flightRow['baggage'];
            if (!empty($flightRow['display_fare']) && (float)($flightRow['fare_amount'] ?? 0)>0) $parts[]='Indicative fare '.crmMoney((float)$flightRow['fare_amount'],(string)($flightRow['currency'] ?? $currencySymbol));
            $add($lines,implode(' | ',$parts));
        }
        $add($lines,'Flight details are suggested for itinerary planning only. Schedules, availability, fares and baggage allowances may change until booking is confirmed and ticketed.','subtle');
    }

    $trainSuggestions = is_array($snapshot['train_suggestions'] ?? null) ? $snapshot['train_suggestions'] : [];
    if ($trainSuggestions) {
        $lines[] = ['text' => '', 'type' => 'space'];
        $add($lines, 'SUGGESTED TRAIN OPTIONS', 'section');
        foreach ($trainSuggestions as $trainRow) {
            if (!is_array($trainRow)) continue;
            $parts=[];
            $parts[]='Sector '.(int)($trainRow['sector_order'] ?? 1).': '.trim((string)($trainRow['origin_code'] ?? '')).' to '.trim((string)($trainRow['destination_code'] ?? ''));
            $parts[]=trim((string)($trainRow['train_name'] ?? '').' '.(string)($trainRow['train_number'] ?? ''));
            if (!empty($trainRow['departure_at'])) $parts[]='Departure '.crmDate((string)$trainRow['departure_at']).' '.date('H:i',strtotime((string)$trainRow['departure_at']));
            if (!empty($trainRow['arrival_at'])) $parts[]='Arrival '.crmDate((string)$trainRow['arrival_at']).' '.date('H:i',strtotime((string)$trainRow['arrival_at']));
            if (!empty($trainRow['travel_class'])) $parts[]='Class '.(string)$trainRow['travel_class'];
            if (!empty($trainRow['display_fare']) && (float)($trainRow['fare_amount'] ?? 0)>0) $parts[]='Indicative fare '.crmMoney((float)$trainRow['fare_amount'],(string)($trainRow['currency'] ?? $currencySymbol));
            $add($lines,implode(' | ',$parts));
        }
        $add($lines,'Train details are suggested for itinerary planning only. Schedule, availability, fare and class availability may change until the ticket is confirmed.','subtle');
    }

    $itinerary = is_array($snapshot['itinerary_days'] ?? null) ? $snapshot['itinerary_days'] : [];

    if ($itinerary) {
        $lines[] = ['text' => '', 'type' => 'space'];
        $add($lines, 'DAY-WISE ITINERARY', 'section');

        foreach ($itinerary as $day) {
            $add(
                $lines,
                'Day ' . (int)($day['day_number'] ?? 0) .
                ' - ' .
                trim((string)($day['title'] ?? '')),
                'day'
            );

            $description = trim((string)($day['description'] ?? ''));

            if ($description !== '') {
                foreach (preg_split('/\R+/', $description) ?: [] as $paragraph) {
                    if (trim($paragraph) !== '') {
                        $add($lines, trim($paragraph));
                    }
                }
            }
        }
    }

    $sections = [
        'tour_highlights' => 'TOUR HIGHLIGHTS',
        'inclusions' => 'INCLUSIONS',
        'exclusions' => 'EXCLUSIONS',
        'terms' => 'TERMS & CONDITIONS',
        'customer_notes' => 'CUSTOMER NOTES',
    ];

    foreach ($sections as $key => $label) {
        $value = trim((string)($snapshot[$key] ?? ''));

        if ($value === '') {
            continue;
        }

        $lines[] = ['text' => '', 'type' => 'space'];
        $add($lines, $label, 'section');

        foreach (preg_split('/\R+/', $value) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $add($lines, trim($paragraph));
            }
        }
    }

    $lines[] = ['text' => '', 'type' => 'space'];
    $add($lines, 'Thank you for choosing ' . $siteName . '.', 'footer');

    return $lines;
}


/*
|--------------------------------------------------------------------------
| QUOTATION BRAND / CONTACT / PDF IMAGE HELPERS
|--------------------------------------------------------------------------
*/

function crmQuotationSettingFirst(
    PDO $pdo,
    array $keys,
    string $default = ''
): string {
    if (!function_exists('getSetting')) {
        return $default;
    }

    foreach ($keys as $key) {
        try {
            $value =
                trim(
                    (string)getSetting(
                        $pdo,
                        (string)$key,
                        ''
                    )
                );

            if ($value !== '') {
                return $value;
            }
        } catch (Throwable $e) {
            // Try the next compatible setting key.
        }
    }

    return $default;
}


function crmQuotationBrandDetails(PDO $pdo): array
{
    $siteName =
        crmQuotationSettingFirst(
            $pdo,
            ['site_name', 'company_name', 'business_name'],
            'TRAVSCOPE.COM'
        );

    $email =
        crmQuotationSettingFirst(
            $pdo,
            [
                'support_email',
                'contact_email',
                'company_email',
                'invoice_email',
                'email'
            ],
            defined('MAIL_REPLY_TO')
                ? (string)MAIL_REPLY_TO
                : (
                    defined('MAIL_FROM_ADDRESS')
                        ? (string)MAIL_FROM_ADDRESS
                        : ''
                )
        );

    $phone =
        crmQuotationSettingFirst(
            $pdo,
            [
                'support_phone',
                'contact_phone',
                'company_phone',
                'invoice_phone',
                'phone',
                'mobile'
            ],
            ''
        );

    $website =
        crmQuotationSettingFirst(
            $pdo,
            ['website', 'site_url', 'company_website'],
            defined('BASE_URL')
                ? rtrim((string)BASE_URL, '/')
                : ''
        );

    $address =
        crmQuotationSettingFirst(
            $pdo,
            [
                'contact_address',
                'company_address',
                'office_address',
                'invoice_address',
                'address'
            ],
            ''
        );

    // Customer-facing quotation documents use a dedicated Document Logo
    // setting. Website/admin branding remains independent.
    $logo =
        crmQuotationSettingFirst(
            $pdo,
            ['document_logo'],
            'assets/travscope-document-logo-wide.png'
        );

    $pdfLogo =
        crmQuotationSettingFirst(
            $pdo,
            ['document_logo_pdf'],
            ''
        );

    if (trim($pdfLogo) === '') {
        $pdfLogo = preg_match('/\.jpe?g(?:[?#].*)?$/i', $logo)
            ? $logo
            : 'assets/travscope-document-logo-wide.jpg';
    }

    $credentialsHeading = crmQuotationSettingFirst(
        $pdo,
        ['quotation_credentials_heading'],
        'Recognitions & Associations'
    );

    require_once __DIR__ . '/credential-display.php';
    $credentials = tsCredentialItems($pdo);

    return [
        'site_name' => $siteName,
        'header_name' => $siteName,
        'email' => $email,
        'phone' => $phone,
        'website' => $website,
        'address' => $address,
        'logo' => $logo,
        'pdf_logo' => $pdfLogo,
        'credentials_heading' => $credentialsHeading,
        'credentials' => $credentials,
    ];
}


function crmQuotationResolveLocalImage(string $image): ?string
{
    $image = trim($image);

    if ($image === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $image)) {
        return null;
    }

    if (str_starts_with($image, 'data:image/')) {
        return null;
    }

    $relative =
        urldecode(
            ltrim(
                $image,
                '/'
            )
        );

    $path =
        __DIR__
        . '/'
        . $relative;

    if (
        !is_file($path)
        ||
        @getimagesize($path) === false
    ) {
        return null;
    }

    return $path;
}


function crmQuotationPdfImage(string $image): ?array
{
    $image = trim($image);
    if ($image === '') return null;

    $bytes = null;
    $info = null;

    /*
    | Remote Unsplash image support for generated quotation PDFs.
    | The CRM stores the official hotlinked images.unsplash.com URL. For the
    | PDF binary only, fetch the image at render time without creating a local
    | permanent copy.
    */
    if (preg_match('#^https://images\\.unsplash\\.com/#i', $image)) {
        try {
            $request = tsPexelsHttpRequest(
                $image,
                ['Accept: image/*', 'Connection: close'],
                null,
                35
            );
            if ((int)$request['status'] < 200 || (int)$request['status'] >= 300) {
                return null;
            }
            $bytes = (string)$request['body'];
            if ($bytes === '') return null;
            $info = @getimagesizefromstring($bytes);
        } catch (Throwable $e) {
            error_log('Unsplash PDF image fetch failed: ' . $e->getMessage());
            return null;
        }
    } else {
        $path = crmQuotationResolveLocalImage($image);
        if ($path === null) return null;
        $info = @getimagesize($path);
        if (!$info) return null;
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') return null;
    }

    if (!$info) return null;

    $mime = strtolower((string)($info['mime'] ?? ''));

    if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
        return [
            'data' => (string)$bytes,
            'width' => (int)$info[0],
            'height' => (int)$info[1],
            'color_space' => !empty($info['channels']) && (int)$info['channels'] === 1
                ? '/DeviceGray'
                : '/DeviceRGB',
            'filter' => '/DCTDecode',
        ];
    }

    if ($mime === 'image/png' && function_exists('tsReceiptPdfPngRgb')) {
        $png = tsReceiptPdfPngRgb((string)$bytes);
        if (is_array($png)) {
            return [
                'data' => (string)$png['data'],
                'width' => (int)$png['width'],
                'height' => (int)$png['height'],
                'color_space' => (string)($png['color_space'] ?? '/DeviceRGB'),
                'filter' => (string)($png['filter'] ?? '/FlateDecode'),
            ];
        }
    }

    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $resource = @imagecreatefromstring((string)$bytes);
        if ($resource === false) return null;

        $width = imagesx($resource);
        $height = imagesy($resource);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $resource, 0, 0, 0, 0, $width, $height);
        ob_start();
        imagejpeg($canvas, null, 88);
        $jpeg = (string)ob_get_clean();
        imagedestroy($resource);
        imagedestroy($canvas);

        if ($jpeg === '') return null;
        return [
            'data' => $jpeg,
            'width' => $width,
            'height' => $height,
            'color_space' => '/DeviceRGB',
            'filter' => '/DCTDecode',
        ];
    }

    return null;
}

function crmQuotationPdfBytes(
    array $version,
    array $lead,
    array $snapshot,
    string $siteName,
    string $currencySymbol
): string {

    global $pdo;

    $brand =
        isset($pdo)
        && $pdo instanceof PDO
            ? crmQuotationBrandDetails($pdo)
            : [
                'site_name' => $siteName,
                'header_name' => $siteName,
                'email' => '',
                'phone' => '',
                'website' => defined('BASE_URL')
                    ? rtrim((string)BASE_URL, '/')
                    : '',
                'address' => '',
                'logo' => '',
            ];

    if (trim((string)($brand['site_name'] ?? '')) !== '') {
        $siteName = (string)$brand['site_name'];
    }
    $documentHeaderName = trim((string)($brand['header_name'] ?? $siteName)) ?: $siteName;

    /*
    |--------------------------------------------------------------------------
    | PDF TEXT HELPERS
    |--------------------------------------------------------------------------
    */

    $escape = static function (string $value): string {
        $value = str_replace(
            ['₹', '–', '—', '•', '✓', '→', '’', '“', '”'],
            ['INR ', '-', '-', '-', '', '->', "'", '"', '"'],
            $value
        );

        $value = preg_replace('/[^\\x20-\\x7E]/', '', $value) ?? $value;

        return str_replace(
            ["\\", "(", ")", "\r", "\n", "\t"],
            ["\\\\", "\\(", "\\)", "", " ", " "],
            $value
        );
    };

    $wrap = static function (string $value, int $width = 88): array {
        $value = trim(preg_replace('/\\s+/', ' ', $value) ?? $value);
        if ($value === '') return [];
        return explode("\n", wordwrap($value, max(24, $width), "\n", true));
    };

    /*
    |--------------------------------------------------------------------------
    | LOCAL IMAGE REGISTRY
    |--------------------------------------------------------------------------
    */

    $imageEntries = [];
    $imageMap = [];

    $registerImage = static function (string $path) use (&$imageEntries, &$imageMap): ?string {
        $path = trim($path);
        if ($path === '') return null;

        $key = strtolower($path);
        if (isset($imageMap[$key])) return $imageMap[$key];

        $image = crmQuotationPdfImage($path);
        if ($image === null) return null;

        $name = 'Im' . (count($imageEntries) + 1);
        $imageEntries[$name] = $image;
        $imageMap[$key] = $name;
        return $name;
    };

    $logoImageName = $registerImage((string)($brand['pdf_logo'] ?? $brand['logo'] ?? ''));
    $mainImageName = $registerImage((string)($snapshot['main_image'] ?? ''));

    $itinerary = is_array($snapshot['itinerary_days'] ?? null)
        ? $snapshot['itinerary_days']
        : [];

    foreach ($itinerary as $index => &$day) {
        $day['_pdf_image_name'] = $registerImage((string)($day['image'] ?? ''));
    }
    unset($day);

    /*
    |--------------------------------------------------------------------------
    | PDF PRIMITIVES
    |--------------------------------------------------------------------------
    */

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

    $nextObjectId = 5;
    $imageObjectIds = [];

    foreach ($imageEntries as $imageName => $image) {
        $imageObjectIds[$imageName] = $nextObjectId++;
        $objects[$imageObjectIds[$imageName]] =
            '<< /Type /XObject '
            . '/Subtype /Image '
            . '/Width ' . (int)$image['width'] . ' '
            . '/Height ' . (int)$image['height'] . ' '
            . '/ColorSpace ' . (string)$image['color_space'] . ' '
            . '/BitsPerComponent 8 '
            . '/Filter ' . (string)($image['filter'] ?? '/DCTDecode') . ' '
            . '/Length ' . strlen((string)$image['data'])
            . ">>\nstream\n"
            . (string)$image['data']
            . "\nendstream";
    }

    $fillRect = static function (
        string &$stream,
        float $x,
        float $y,
        float $w,
        float $h,
        array $rgb
    ): void {
        $stream .= sprintf(
            "%.3F %.3F %.3F rg\n%.2F %.2F %.2F %.2F re f\n",
            $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h
        );
    };

    $strokeRect = static function (
        string &$stream,
        float $x,
        float $y,
        float $w,
        float $h,
        array $rgb,
        float $lineWidth = 0.8
    ): void {
        $stream .= sprintf(
            "%.3F %.3F %.3F RG\n%.2F w\n%.2F %.2F %.2F %.2F re S\n",
            $rgb[0], $rgb[1], $rgb[2], $lineWidth, $x, $y, $w, $h
        );
    };

    $addText = static function (
        string &$stream,
        string $font,
        float $size,
        float $x,
        float $y,
        string $value,
        array $rgb
    ) use ($escape): void {
        $stream .=
            "BT\n"
            . sprintf("%.3F %.3F %.3F rg\n", $rgb[0], $rgb[1], $rgb[2])
            . '/' . $font . ' ' . $size . " Tf\n"
            . sprintf("1 0 0 1 %.2F %.2F Tm\n", $x, $y)
            . '(' . $escape($value) . ") Tj\nET\n";
    };

    $drawImage = static function (
        string &$stream,
        ?string $imageName,
        float $x,
        float $y,
        float $boxW,
        float $boxH
    ) use ($imageEntries): void {
        if ($imageName === null || !isset($imageEntries[$imageName])) return;

        $image = $imageEntries[$imageName];
        $iw = max(1, (float)$image['width']);
        $ih = max(1, (float)$image['height']);

        // Cover/crop style: fill the box like the HTML preview object-fit:cover.
        $scale = max($boxW / $iw, $boxH / $ih);
        $w = $iw * $scale;
        $h = $ih * $scale;
        $ix = $x + ($boxW - $w) / 2;
        $iy = $y + ($boxH - $h) / 2;

        // Clip to image box.
        $stream .= "q\n";
        $stream .= sprintf("%.2F %.2F %.2F %.2F re W n\n", $x, $y, $boxW, $boxH);
        $stream .= sprintf("%.2F 0 0 %.2F %.2F %.2F cm\n", $w, $h, $ix, $iy);
        $stream .= '/' . $imageName . " Do\nQ\n";
    };

    /*
    |--------------------------------------------------------------------------
    | PAGE MANAGEMENT — COMPACT LIKE THE BROWSER PRINT PREVIEW
    |--------------------------------------------------------------------------
    */

    $pageStreams = [];
    $stream = '';
    $y = 0.0;
    $pageNo = 0;

    $contactBits = [];
    foreach ([(string)($brand['email'] ?? ''), (string)($brand['website'] ?? '')] as $bit) {
        if (trim($bit) !== '') $contactBits[] = trim($bit);
    }
    $contactLine = implode(' • ', $contactBits);

    $startPage = static function (
        string &$stream,
        float &$y,
        int &$pageNo,
        bool $firstPage
    ) use ($fillRect, $addText, $drawImage, $logoImageName, $siteName, $contactLine, $documentHeaderName): void {
        $pageNo++;
        $stream = '';
        $fillRect($stream, 0, 0, 595, 842, [1, 1, 1]);

        if ($firstPage) {
            // International document header: 3-inch logo + compact legal identity.
            if ($logoImageName !== null) {
                $drawImage($stream, $logoImageName, 34, 744, 216, 83);
            }
            $addText($stream, 'F2', 11.2, 274, 808, $documentHeaderName, [0.027,0.098,0.176]);
            $addText($stream, 'F1', 8.0, 274, 790, 'Trade Name: Travscope', [0.380,0.450,0.550]);
            if ($contactLine !== '') {
                $addText($stream, 'F1', 7.5, 274, 775, $contactLine, [0.380,0.450,0.550]);
            }

            $fillRect($stream, 444, 742, 109, 28, [0.925,0.965,1.000]);
            $addText($stream, 'F2', 8.3, 456, 752, 'OFFICIAL QUOTATION', [0.020,0.390,0.760]);
            $y = 722;
        } else {
            // Subsequent pages intentionally start high — no repeating giant header.
            $y = 795;
        }
    };

    $finishPage = static function (string &$stream, int $pageNo) use ($addText, $siteName): void {
        $addText($stream, 'F1', 7.2, 45, 20, 'TRAVSCOPE • Personalized Travel Quotation', [0.450,0.500,0.570]);
        $addText($stream, 'F1', 7.2, 525, 20, (string)$pageNo, [0.450,0.500,0.570]);
    };

    $newPageIfNeeded = static function (
        float $height,
        string &$stream,
        float &$y,
        int &$pageNo,
        array &$pageStreams
    ) use ($finishPage, $startPage): void {
        if ($y - $height < 46) {
            $finishPage($stream, $pageNo);
            $pageStreams[] = $stream;
            $startPage($stream, $y, $pageNo, false);
        }
    };

    $startPage($stream, $y, $pageNo, true);

    /*
    |--------------------------------------------------------------------------
    | PAGE 1 HERO — SAME COMPOSITION AS PREVIEW
    |--------------------------------------------------------------------------
    */

    $heroH = 220;
    $heroY = $y - $heroH;

    // Two-tone blue hero approximates the HTML gradient.
    $fillRect($stream, 28, $heroY, 539, $heroH, [0.035,0.150,0.285]);
    $fillRect($stream, 350, $heroY, 217, $heroH, [0.045,0.390,0.880]);

    // Kicker pill.
    $fillRect($stream, 57, $heroY + 137, 250, 27, [0.140,0.300,0.465]);
    $addText(
        $stream,
        'F2',
        8.5,
        66,
        $heroY + 147,
        'PERSONALIZED TRAVEL QUOTATION • VERSION ' . (int)($version['version_number'] ?? 0),
        [0.850,0.930,1.000]
    );

    $titleLines = $wrap((string)($version['package_title'] ?? 'Travel Package'), 28);
    $titleY = $heroY + 101;
    foreach (array_slice($titleLines, 0, 3) as $line) {
        $addText($stream, 'F2', 22, 57, $titleY, $line, [1,1,1]);
        $titleY -= 30;
    }

    if ($mainImageName !== null) {
        $fillRect($stream, 363, $heroY + 29, 177, 162, [0.770,0.830,0.900]);
        $fillRect($stream, 367, $heroY + 33, 169, 154, [1,1,1]);
        $drawImage($stream, $mainImageName, 370, $heroY + 36, 163, 148);
    }

    $y = $heroY - 24;

    /*
    |--------------------------------------------------------------------------
    | PREPARED-FOR STRIP
    |--------------------------------------------------------------------------
    */

    $stripH = 56;
    $fillRect($stream, 52, $y - $stripH, 491, $stripH, [0.985,0.992,1.000]);
    $strokeRect($stream, 52, $y - $stripH, 491, $stripH, [0.820,0.875,0.940], 0.8);

    $customerName = (string)($lead['customer_name'] ?? 'Customer');
    $addText($stream, 'F2', 11.7, 66, $y - 24, 'Prepared especially for ' . $customerName, [0.027,0.098,0.176]);
    $addText($stream, 'F1', 8.5, 66, $y - 42, 'Your complete package, pricing and travel plan in one clear quotation.', [0.430,0.500,0.600]);
    $addText($stream, 'F2', 11.5, 454, $y - 31, strtoupper($siteName), [0.027,0.098,0.176]);
    $y -= $stripH + 17;

    /*
    |--------------------------------------------------------------------------
    | SIX HIGHLIGHT CARDS
    |--------------------------------------------------------------------------
    */

    $cards = [
        ['CUSTOMER', $customerName, [0.925,0.965,1.000], [0.043,0.455,1.000]],
        ['MOBILE', (string)($lead['customer_mobile'] ?? '-'), [0.957,0.941,1.000], [0.420,0.320,0.780]],
        ['EMAIL', (string)($lead['customer_email'] ?? '-'), [1.000,0.965,0.900], [0.780,0.480,0.040]],
        ['TRAVEL DATE', crmDate((string)($version['travel_date'] ?? '')), [0.930,0.980,0.950], [0.030,0.530,0.370]],
        ['TRAVELLERS', (int)($version['adults'] ?? 0) . ' Adult(s) + ' . (int)($version['children'] ?? 0) . ' Child(ren)', [1.000,0.945,0.955], [0.830,0.250,0.330]],
        ['FINAL QUOTE', crmMoney((float)($version['final_price'] ?? 0), $currencySymbol), [0.910,0.985,0.950], [0.020,0.480,0.320]],
    ];

    $cardW = 155;
    $cardH = 68;
    $gapX = 12;
    $gapY = 10;

    foreach ($cards as $i => $card) {
        $row = intdiv($i, 3);
        $col = $i % 3;
        $x = 52 + $col * ($cardW + $gapX);
        $cy = $y - $row * ($cardH + $gapY) - $cardH;

        $fillRect($stream, $x, $cy, $cardW, $cardH, $card[2]);
        $strokeRect($stream, $x, $cy, $cardW, $cardH, [0.835,0.875,0.925], 0.55);
        $addText($stream, 'F2', 7.3, $x + 11, $cy + 45, $card[0], [0.430,0.500,0.590]);

        $valueLines = $wrap($card[1], 25);
        foreach (array_slice($valueLines, 0, 2) as $li => $line) {
            $addText(
                $stream,
                'F2',
                $card[0] === 'FINAL QUOTE' ? 12.2 : 9.4,
                $x + 11,
                $cy + 25 - $li * 12,
                $line,
                $card[3]
            );
        }
    }
    $y -= 2 * $cardH + $gapY + 22;

    /*
    |--------------------------------------------------------------------------
    | REUSABLE SECTION HEADING + TEXT BOX
    |--------------------------------------------------------------------------
    */

    $drawSectionHeading = static function (
        string &$stream,
        float &$y,
        string $number,
        string $title,
        array $accent
    ) use ($fillRect, $addText): void {
        $fillRect($stream, 52, $y - 30, 30, 30, [0.925,0.965,1.000]);
        $addText($stream, 'F2', 9.8, 59, $y - 19, $number, $accent);
        $addText($stream, 'F2', 14.0, 90, $y - 20, $title, [0.027,0.098,0.176]);
        $y -= 39;
    };

    $drawTextSection = static function (
        string &$stream,
        float &$y,
        int &$pageNo,
        array &$pageStreams,
        string $number,
        string $title,
        string $textValue,
        array $accent,
        array $bg,
        int $wrapWidth = 86
    ) use ($wrap, $newPageIfNeeded, $drawSectionHeading, $fillRect, $strokeRect, $addText): void {
        $paragraphs = preg_split('/\\R+/', $textValue) ?: [];
        $lines = [];
        foreach ($paragraphs as $p) {
            foreach ($wrap(trim($p), $wrapWidth) as $l) $lines[] = $l;
        }
        if (!$lines) return;

        $lineH = 13.2;
        $padding = 17;
        $boxH = $padding * 2 + count($lines) * $lineH;

        // If whole section fits, keep it together. Otherwise split gracefully.
        if ($boxH + 46 <= 690) {
            $newPageIfNeeded($boxH + 46, $stream, $y, $pageNo, $pageStreams);
            $drawSectionHeading($stream, $y, $number, $title, $accent);
            $fillRect($stream, 52, $y - $boxH, 491, $boxH, $bg);
            $strokeRect($stream, 52, $y - $boxH, 491, $boxH, [0.850,0.885,0.925], 0.55);
            $cursor = $y - $padding - 1;
            foreach ($lines as $line) {
                $addText($stream, 'F1', 9.1, 66, $cursor, $line, [0.310,0.380,0.480]);
                $cursor -= $lineH;
            }
            $y -= $boxH + 16;
            return;
        }

        // Long section: heading, then as many lines as each page can hold.
        $newPageIfNeeded(60, $stream, $y, $pageNo, $pageStreams);
        $drawSectionHeading($stream, $y, $number, $title, $accent);
        $remaining = $lines;
        while ($remaining) {
            $available = max(70, $y - 52);
            $maxLines = max(3, (int)floor(($available - 34) / $lineH));
            $chunk = array_splice($remaining, 0, $maxLines);
            $chunkH = $padding * 2 + count($chunk) * $lineH;
            $fillRect($stream, 52, $y - $chunkH, 491, $chunkH, $bg);
            $strokeRect($stream, 52, $y - $chunkH, 491, $chunkH, [0.850,0.885,0.925], 0.55);
            $cursor = $y - $padding - 1;
            foreach ($chunk as $line) {
                $addText($stream, 'F1', 9.1, 66, $cursor, $line, [0.310,0.380,0.480]);
                $cursor -= $lineH;
            }
            $y -= $chunkH + 16;
            if ($remaining) {
                $finishPage = null; // placeholder to satisfy closure scope; pagination handled below
                $newPageIfNeeded(740, $stream, $y, $pageNo, $pageStreams);
            }
        }
    };

    /* Package overview starts on page 1 exactly like the preview. */
    $description = trim((string)($snapshot['description'] ?? ''));
    if ($description !== '') {
        $drawTextSection(
            $stream, $y, $pageNo, $pageStreams,
            '01', 'Package Overview', $description,
            [0.043,0.455,1.000], [0.985,0.990,0.997], 86
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PRICING TABLE
    |--------------------------------------------------------------------------
    */

    $priceRows = is_array($snapshot['date_prices'] ?? null) ? $snapshot['date_prices'] : [];
    if ($priceRows) {
        $required = 76 + count($priceRows) * 28;
        $newPageIfNeeded($required, $stream, $y, $pageNo, $pageStreams);
        $drawSectionHeading($stream, $y, '02', 'Date-wise Hotel Pricing', [0.043,0.455,1.000]);

        $fillRect($stream, 52, $y - 28, 491, 28, [0.027,0.098,0.176]);
        $headers = [
            ['Valid From', 10], ['Valid To', 125], ['3 Star', 235], ['4 Star', 327], ['5 Star', 419]
        ];
        foreach ($headers as [$label, $offset]) {
            $addText($stream, 'F2', 7.8, 52 + $offset, $y - 18, $label, [1,1,1]);
        }
        $y -= 28;

        foreach (array_values($priceRows) as $ri => $row) {
            $fillRect($stream, 52, $y - 28, 491, 28, $ri % 2 === 0 ? [0.990,0.993,0.997] : [0.965,0.975,0.987]);
            $vals = [
                crmDate((string)($row['valid_from'] ?? '')),
                crmDate((string)($row['valid_to'] ?? '')),
                crmMoney((float)($row['price_3_star'] ?? 0), $currencySymbol),
                crmMoney((float)($row['price_4_star'] ?? 0), $currencySymbol),
                crmMoney((float)($row['price_5_star'] ?? 0), $currencySymbol),
            ];
            $xs = [62,177,287,379,471];
            foreach ($vals as $vi => $val) {
                $addText($stream, 'F1', 7.5, $xs[$vi], $y - 18, $val, [0.190,0.250,0.330]);
            }
            $y -= 28;
        }
        $y -= 17;
    }

    $pdfFlightSuggestions = is_array($snapshot['flight_suggestions'] ?? null) ? $snapshot['flight_suggestions'] : [];
    if ($pdfFlightSuggestions) {
        $flightText=[];
        foreach($pdfFlightSuggestions as $f){
            if(!is_array($f))continue;
            $line='Suggested Flight · '.(string)($f['origin_code']??'').' → '.(string)($f['destination_code']??'');
            $airline=trim((string)($f['airline_name']??'').' '.(string)($f['flight_number']??'')); if($airline!=='')$line.=' · '.$airline;
            if(!empty($f['departure_at']))$line.=' · '.date('d M Y H:i',strtotime((string)$f['departure_at']));
            $line.=' · '.((int)($f['stops']??0)===0?'Direct':(int)$f['stops'].' Stop(s)');
            if(!empty($f['display_fare'])&&(float)($f['fare_amount']??0)>0)$line.=' · Indicative fare '.(string)($f['currency']??'INR').' '.number_format((float)$f['fare_amount'],2);
            $flightText[]=$line;
        }
        $flightText[]='Flight details are suggested for itinerary planning only. Schedules, availability, fares and baggage allowances may change until booking is confirmed and ticketed.';
        $drawTextSection($stream,$y,$pageNo,$pageStreams,'F','Suggested Flight Options',implode("\n",$flightText),[0.125,0.360,0.820],[0.955,0.975,1.000],86);
    }

    $pdfTrainSuggestions = is_array($snapshot['train_suggestions'] ?? null) ? $snapshot['train_suggestions'] : [];
    if ($pdfTrainSuggestions) {
        $trainText=[];
        foreach($pdfTrainSuggestions as $r){
            if(!is_array($r))continue;
            $line='Suggested Train · '.(string)($r['origin_code']??'').' → '.(string)($r['destination_code']??'').' · '.trim((string)($r['train_name']??'').' '.(string)($r['train_number']??''));
            if(!empty($r['departure_at']))$line.=' · '.date('d M Y H:i',strtotime((string)$r['departure_at']));
            if(!empty($r['travel_class']))$line.=' · Class '.(string)$r['travel_class'];
            if(!empty($r['display_fare'])&&(float)($r['fare_amount']??0)>0)$line.=' · Indicative fare '.(string)($r['currency']??'INR').' '.number_format((float)$r['fare_amount'],2);
            $trainText[]=$line;
        }
        $trainText[]='Train details are suggested for itinerary planning only. Schedule, availability, fare and class availability may change until the ticket is confirmed.';
        $drawTextSection($stream,$y,$pageNo,$pageStreams,'T','Suggested Train Options',implode("\n",$trainText),[0.030,0.530,0.370],[0.950,0.990,0.970],86);
    }

    /*
    |--------------------------------------------------------------------------
    | DAY-WISE ITINERARY — FULL-WIDTH PREMIUM PHOTO BROCHURE
    |--------------------------------------------------------------------------
    |
    | Image appears above the itinerary text like a premium travel brochure.
    | The image is fitted using its original aspect ratio and is never cropped.
    |
    */

    if ($itinerary) {

        $newPageIfNeeded(
            48,
            $stream,
            $y,
            $pageNo,
            $pageStreams
        );

        $drawSectionHeading(
            $stream,
            $y,
            '03',
            'Day-wise Itinerary',
            [0.043,0.455,1.000]
        );

        foreach ($itinerary as $day) {

            $dayRawTitle=trim((string)($day['title'] ?? ''));
            $dayRawDescription=trim((string)($day['description'] ?? ''));
            if($dayRawTitle==='' && $dayRawDescription==='' && empty($day['_pdf_image_name'])) continue;

            $dayTitle =
                'Day '
                . (int)($day['day_number'] ?? 0)
                . ($dayRawTitle !== '' ? ' — ' . $dayRawTitle : '');

            $dayLines = [];

            foreach (
                preg_split(
                    '/\\R+/',
                    (string)($day['description'] ?? '')
                )
                ?: []
                as $paragraph
            ) {
                foreach (
                    $wrap(
                        trim($paragraph),
                        86
                    )
                    as $line
                ) {
                    $dayLines[] = $line;
                }
            }

            $hasImage =
                !empty(
                    $day['_pdf_image_name']
                );

            /*
            |--------------------------------------------------------------------------
            | AUTO IMAGE HEIGHT FROM ORIGINAL RATIO
            |--------------------------------------------------------------------------
            */

            $photoHeight = 0;

            if ($hasImage) {

                $imageName =
                    (string)$day['_pdf_image_name'];

                $imageMeta =
                    $imageEntries[$imageName]
                    ?? null;

                $photoWidth = 463.0;

                if (
                    is_array($imageMeta)
                    &&
                    !empty($imageMeta['width'])
                    &&
                    !empty($imageMeta['height'])
                ) {
                    $ratio =
                        (float)$imageMeta['height']
                        /
                        max(
                            1.0,
                            (float)$imageMeta['width']
                        );

                    $photoHeight =
                        $photoWidth
                        * $ratio;
                } else {
                    $photoHeight = 185;
                }

                /*
                | Landscape photos become wide brochure banners.
                | Portrait photos stay fully visible without becoming too tall.
                */
                $photoHeight =
                    max(
                        125,
                        min(
                            245,
                            $photoHeight
                        )
                    );
            }

            $lineHeight = 12.4;

            /*
            |--------------------------------------------------------------------------
            | SPLIT TEXT INTO PAGE-SAFE CHUNKS
            |--------------------------------------------------------------------------
            */

            $remaining =
                $dayLines;

            $firstChunk = true;

            if (!$remaining) {
                $remaining = [''];
            }

            while ($remaining) {

                $imageSpace =
                    $firstChunk
                    && $hasImage
                        ? $photoHeight + 18
                        : 0;

                /*
                | Need at least enough room for photo + title + a few text lines.
                */
                $minimumHeight =
                    $imageSpace
                    + 82;

                $newPageIfNeeded(
                    $minimumHeight,
                    $stream,
                    $y,
                    $pageNo,
                    $pageStreams
                );

                $availableHeight =
                    $y - 58;

                $usableForText =
                    $availableHeight
                    - $imageSpace
                    - 57;

                $maxLines =
                    max(
                        2,
                        (int)floor(
                            $usableForText
                            / $lineHeight
                        )
                    );

                $chunk =
                    array_splice(
                        $remaining,
                        0,
                        $maxLines
                    );

                $textHeight =
                    49
                    + max(
                        1,
                        count($chunk)
                    )
                    * $lineHeight;

                $cardHeight =
                    $imageSpace
                    + $textHeight
                    + 13;

                /*
                |--------------------------------------------------------------------------
                | CARD
                |--------------------------------------------------------------------------
                */

                $cardBottom =
                    $y
                    - $cardHeight;

                $fillRect(
                    $stream,
                    52,
                    $cardBottom,
                    491,
                    $cardHeight,
                    [0.995,0.997,1.000]
                );

                $strokeRect(
                    $stream,
                    52,
                    $cardBottom,
                    491,
                    $cardHeight,
                    [0.850,0.885,0.925],
                    0.55
                );

                $contentTop =
                    $y;

                /*
                |--------------------------------------------------------------------------
                | FULL-WIDTH PHOTO ON TOP
                |--------------------------------------------------------------------------
                */

                if (
                    $firstChunk
                    && $hasImage
                ) {
                    $imageName =
                        (string)$day['_pdf_image_name'];

                    $photoX = 65;
                    $photoW = 465;
                    $photoY =
                        $y
                        - $photoHeight
                        - 12;

                    /*
                    | Soft photo stage + border.
                    */
                    $fillRect(
                        $stream,
                        63,
                        $photoY - 2,
                        469,
                        $photoHeight + 4,
                        [0.945,0.960,0.976]
                    );

                    $strokeRect(
                        $stream,
                        63,
                        $photoY - 2,
                        469,
                        $photoHeight + 4,
                        [0.805,0.850,0.905],
                        0.55
                    );

                    /*
                    | drawImage() uses contain-fit, preserving the original ratio.
                    */
                    $drawImage(
                        $stream,
                        $imageName,
                        $photoX,
                        $photoY,
                        $photoW,
                        $photoHeight
                    );

                    $contentTop =
                        $photoY - 12;
                }

                /*
                |--------------------------------------------------------------------------
                | TITLE + DESCRIPTION BELOW PHOTO
                |--------------------------------------------------------------------------
                */

                $title =
                    $firstChunk
                        ? $dayTitle
                        : $dayTitle . ' (continued)';

                $addText(
                    $stream,
                    'F2',
                    10.2,
                    67,
                    $contentTop - 20,
                    $title,
                    [0.027,0.098,0.176]
                );

                $cursor =
                    $contentTop - 41;

                foreach ($chunk as $line) {

                    if ($line !== '') {
                        $addText(
                            $stream,
                            'F1',
                            8.9,
                            67,
                            $cursor,
                            $line,
                            [0.330,0.400,0.490]
                        );
                    }

                    $cursor -=
                        $lineHeight;
                }

                $y -=
                    $cardHeight + 12;

                $firstChunk = false;
            }
        }

        $y -= 6;
    }

    /*
    |--------------------------------------------------------------------------
    | HIGHLIGHTS / INCLUSIONS / EXCLUSIONS / TERMS / NOTES
    |--------------------------------------------------------------------------
    */

    $sections = [
        ['tour_highlights','04','Highlights',[0.043,0.455,1.000],[0.955,0.978,1.000]],
        ['inclusions','05','Inclusions',[0.030,0.530,0.370],[0.950,0.990,0.970]],
        ['exclusions','06','Exclusions',[0.830,0.250,0.330],[1.000,0.965,0.970]],
        ['terms','07','Terms & Conditions',[0.780,0.480,0.040],[1.000,0.982,0.940]],
        ['customer_notes','08','Customer Notes',[0.420,0.320,0.780],[0.970,0.958,1.000]],
    ];

    foreach ($sections as [$key,$num,$title,$accent,$bg]) {
        $value = trim((string)($snapshot[$key] ?? ''));
        if ($value === '') continue;
        $drawTextSection($stream, $y, $pageNo, $pageStreams, $num, $title, $value, $accent, $bg, 86);
    }

    $finishPage($stream, $pageNo);
    $pageStreams[] = $stream;

    /*
    |--------------------------------------------------------------------------
    | PAGE / CONTENT OBJECTS
    |--------------------------------------------------------------------------
    */

    $pageObjectIds = [];

    foreach ($pageStreams as $pageStream) {
        $pageId = $nextObjectId++;
        $contentId = $nextObjectId++;
        $pageObjectIds[] = $pageId;

        $objects[$contentId] =
            '<< /Length ' . strlen($pageStream) . ">>\nstream\n"
            . $pageStream
            . "endstream";

        $xObjects = '';
        foreach ($imageObjectIds as $imageName => $imageObjectId) {
            $xObjects .= '/' . $imageName . ' ' . $imageObjectId . ' 0 R ';
        }

        $objects[$pageId] =
            '<< /Type /Page '
            . '/Parent 2 0 R '
            . '/MediaBox [0 0 595 842] '
            . '/Resources << '
            . '/Font << /F1 3 0 R /F2 4 0 R >> '
            . ($xObjects !== '' ? '/XObject << ' . $xObjects . '>> ' : '')
            . '>> '
            . '/Contents ' . $contentId . ' 0 R >>';
    }

    $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds));
    $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageObjectIds) . ' >>';

    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0];
    $maxObjectId = max(array_keys($objects));

    for ($id = 1; $id <= $maxObjectId; $id++) {
        if (!isset($objects[$id])) continue;
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . ($maxObjectId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($id = 1; $id <= $maxObjectId; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
    }

    $pdf .=
        "trailer\n"
        . '<< /Size ' . ($maxObjectId + 1) . " /Root 1 0 R >>\n"
        . "startxref\n"
        . $xref
        . "\n%%EOF";

    return $pdf;
}

function crmProfessionalQuotationHtml(
    array $version,
    array $lead,
    array $snapshot,
    string $siteName,
    string $currencySymbol,
    string $pdfUrl
): string {
    global $pdo;
    $emailBrand = isset($pdo) && $pdo instanceof PDO ? crmQuotationBrandDetails($pdo) : ['logo'=>'','header_name'=>$siteName];
    $emailHeaderName = trim((string)($emailBrand['header_name'] ?? $siteName)) ?: $siteName;
    $emailLogoRaw = trim((string)($emailBrand['logo'] ?? ''));
    $emailLogoSrc = '';
    if ($emailLogoRaw !== '') {
        $emailLogoSrc = crmQuotationResolveLocalImage($emailLogoRaw) !== null
            ? 'cid:travscope-logo'
            : crmImageUrl($emailLogoRaw);
    }
    $emailLogoHtml = $emailLogoSrc !== ''
        ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:14px"><tr><td style="vertical-align:middle;width:310px"><img src="' . e($emailLogoSrc) . '" alt="Travscope" width="288" style="display:block;width:288px;max-width:100%;height:auto;background:#ffffff"></td><td style="vertical-align:middle;text-align:right;color:#ffffff;font-size:14px;font-weight:800">' . e($emailHeaderName) . '<div style="margin-top:4px;color:#9ed2ff;font-size:10px;font-weight:700">Trade Name: Travscope</div></td></tr></table>'
        : '<div style="margin-bottom:14px;font-size:15px;font-weight:800;color:#ffffff">' . e($emailHeaderName) . '</div>';
    $customer = e((string)($lead['customer_name'] ?? 'Customer'));
    $package = e((string)($version['package_title'] ?? 'Travel Package'));
    $short = e(trim((string)($snapshot['short_description'] ?? '')));
    $travelDate = e(crmDate((string)($version['travel_date'] ?? '')));
    $duration = (int)($snapshot['duration_days'] ?? 0) . ' Days / ' . (int)($snapshot['duration_nights'] ?? 0) . ' Nights';
    $travellers = (int)($version['adults'] ?? 0) . ' Adult(s) + ' . (int)($version['children'] ?? 0) . ' Child(ren)';
    $price = e(crmMoney((float)($version['final_price'] ?? 0), $currencySymbol));
    $safePdfUrl = e($pdfUrl);

    return '
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f3f6fa;font-family:Arial,sans-serif;color:#172235">

<!-- UNIVERSAL MOBILE SIDEBAR CONTROLS -->





<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6fa;padding:28px 12px">
<tr><td align="center">
<table role="presentation" width="680" cellspacing="0" cellpadding="0" style="width:100%;max-width:680px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #dce5ee">
<tr><td style="padding:28px 30px;background:#07192d;color:#ffffff;border-bottom:5px solid #0b74ff">
' . $emailLogoHtml . '
<div style="font-size:12px;letter-spacing:1.4px;font-weight:700;color:#9ed2ff">PERSONALIZED TRAVEL QUOTATION</div>
<div style="font-size:27px;line-height:1.25;font-weight:800;margin-top:8px">' . $package . '</div>
<div style="margin-top:8px;color:#d9ecff;font-size:14px">' . ($short !== '' ? $short : 'Prepared especially for your upcoming journey.') . '</div>
</td></tr>

<tr><td style="padding:26px 30px 8px">
<div style="font-size:17px;font-weight:800;color:#07192d">Dear ' . $customer . ',</div>
<div style="margin-top:8px;font-size:14px;line-height:1.7;color:#5d6b7e">
Thank you for choosing <strong>' . e($siteName) . '</strong>. Your personalized travel quotation is ready. Key details are highlighted below.
</div>
</td></tr>

<tr><td style="padding:18px 30px">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
<tr>
<td width="50%" style="padding:0 6px 10px 0">
<div style="padding:16px;border-radius:12px;background:#edf5ff;border:1px solid #d7e7ff">
<div style="font-size:11px;color:#5f7390;font-weight:700;text-transform:uppercase">Travel Date</div>
<div style="margin-top:5px;font-size:16px;color:#0b5fb5;font-weight:800">' . $travelDate . '</div>
</div></td>
<td width="50%" style="padding:0 0 10px 6px">
<div style="padding:16px;border-radius:12px;background:#f3efff;border:1px solid #e0d8ff">
<div style="font-size:11px;color:#756797;font-weight:700;text-transform:uppercase">Duration</div>
<div style="margin-top:5px;font-size:16px;color:#6549b8;font-weight:800">' . e($duration) . '</div>
</div></td>
</tr>
<tr>
<td width="50%" style="padding:0 6px 0 0">
<div style="padding:16px;border-radius:12px;background:#fff5e8;border:1px solid #ffe0b7">
<div style="font-size:11px;color:#8a6b43;font-weight:700;text-transform:uppercase">Travellers</div>
<div style="margin-top:5px;font-size:16px;color:#a05c00;font-weight:800">' . e($travellers) . '</div>
</div></td>
<td width="50%" style="padding:0 0 0 6px">
<div style="padding:16px;border-radius:12px;background:#eafaf2;border:1px solid #c8eedb">
<div style="font-size:11px;color:#527d68;font-weight:700;text-transform:uppercase">Final Quote</div>
<div style="margin-top:5px;font-size:20px;color:#087952;font-weight:800">' . $price . '</div>
</div></td>
</tr>
</table>
</td></tr>

<tr><td style="padding:18px 30px 24px">
<div style="padding:17px 18px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;font-size:13px;line-height:1.7;color:#58677a">
<strong style="color:#07192d">Your complete quotation PDF is attached to this email.</strong><br>
The attachment contains your package overview, hotel pricing, day-wise itinerary, inclusions, exclusions, terms and customer-specific quotation details.
</div>
</td></tr>

<tr><td align="center" style="padding:0 30px 30px">
<a href="' . $safePdfUrl . '" style="display:inline-block;padding:13px 24px;border-radius:10px;background:#0b74ff;color:#ffffff;text-decoration:none;font-size:14px;font-weight:800">
View / Download Quotation PDF
</a>
</td></tr>

<tr><td style="padding:18px 30px;background:#07192d;color:#b9c9d9;font-size:12px;line-height:1.6">
<strong style="color:#ffffff">' . e($siteName) . '</strong><br>
Travel thoughtfully. Explore confidently.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
}

function crmProfessionalQuotationPlain(
    array $version,
    array $lead,
    array $snapshot,
    string $siteName,
    string $currencySymbol,
    string $pdfUrl
): string {
    return
        "PERSONALIZED TRAVEL QUOTATION\n"
        . strtoupper($siteName)
        . "\n\nDear "
        . (string)($lead['customer_name'] ?? 'Customer')
        . ",\n\nYour personalized quotation is ready.\n\n"
        . "PACKAGE: " . (string)($version['package_title'] ?? 'Travel Package') . "\n"
        . "TRAVEL DATE: " . crmDate((string)($version['travel_date'] ?? '')) . "\n"
        . "DURATION: " . (int)($snapshot['duration_days'] ?? 0) . " Days / " . (int)($snapshot['duration_nights'] ?? 0) . " Nights\n"
        . "TRAVELLERS: " . (int)($version['adults'] ?? 0) . " Adult(s) + " . (int)($version['children'] ?? 0) . " Child(ren)\n"
        . "FINAL QUOTE: " . crmMoney((float)($version['final_price'] ?? 0), $currencySymbol) . "\n\n"
        . "Your complete quotation PDF is attached to this email.\n"
        . "View / Download: " . $pdfUrl . "\n\n"
        . "Thank you for choosing " . $siteName . ".";
}

function crmProfessionalWhatsAppMessage(
    array $version,
    array $lead,
    array $snapshot,
    string $siteName,
    string $currencySymbol,
    string $pdfUrl
): string {
    $short = trim((string)($snapshot['short_description'] ?? ''));

    $message =
        "✈️ *" . $siteName . " - PERSONALIZED TRAVEL QUOTATION*\n\n"
        . "Hello *" . (string)($lead['customer_name'] ?? 'Customer') . "*, 👋\n"
        . "Your customized travel quotation is ready.\n\n"
        . "🌍 *Package:* " . (string)($version['package_title'] ?? 'Travel Package') . "\n";

    if ($short !== '') {
        $message .= "✨ " . $short . "\n";
    }

    $message .=
        "\n📅 *Travel Date:* " . crmDate((string)($version['travel_date'] ?? '')) . "\n"
        . "🕒 *Duration:* " . (int)($snapshot['duration_days'] ?? 0) . " Days / " . (int)($snapshot['duration_nights'] ?? 0) . " Nights\n"
        . "👥 *Travellers:* " . (int)($version['adults'] ?? 0) . " Adult(s) + " . (int)($version['children'] ?? 0) . " Child(ren)\n"
        . "💰 *Final Quote:* " . crmMoney((float)($version['final_price'] ?? 0), $currencySymbol) . "\n\n"
        . "📄 *Complete Quotation PDF*\n"
        . $pdfUrl
        . "\n\nThe PDF contains your complete package overview, hotel pricing, day-wise itinerary, inclusions, exclusions and terms.\n\n"
        . "Thank you for choosing *" . $siteName . "* 🙏";

    return $message;
}

function crmSendProfessionalQuotationEmail(
    string $to,
    string $subject,
    string $html,
    string $plain,
    string $pdfBytes,
    string $pdfFileName
): bool {
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('TRAVSCOPE CRM: PHPMailer class is unavailable.');
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
            $mail->isSMTP();
            $mail->Host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
            $mail->SMTPAuth = defined('SMTP_AUTH') ? (bool)SMTP_AUTH : true;
            $mail->Username = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
            $mail->Password = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
            $mail->Port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;

            if (defined('SMTP_ENCRYPTION') && trim((string)SMTP_ENCRYPTION) !== '') {
                $mail->SMTPSecure = SMTP_ENCRYPTION;
            }
        }

        $fromAddress = defined('MAIL_FROM_ADDRESS')
            ? MAIL_FROM_ADDRESS
            : (defined('SMTP_USERNAME') ? SMTP_USERNAME : '');

        $fromName = defined('MAIL_FROM_NAME')
            ? MAIL_FROM_NAME
            : 'TRAVSCOPE.COM';

        $mail->setFrom($fromAddress, $fromName);

        if (defined('MAIL_REPLY_TO') && trim((string)MAIL_REPLY_TO) !== '') {
            $mail->addReplyTo(MAIL_REPLY_TO, $fromName);
        }

        $mail->addAddress($to);
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        // Embed the General Settings logo when it is a local uploaded file.
        try {
            global $pdo;
            if (isset($pdo) && $pdo instanceof PDO) {
                $mailBrand = crmQuotationBrandDetails($pdo);
                $mailLogoPath = crmQuotationResolveLocalImage((string)($mailBrand['logo'] ?? ''));
                if ($mailLogoPath !== null) {
                    $mailLogoMime = function_exists('mime_content_type') ? (string)@mime_content_type($mailLogoPath) : '';
                    if ($mailLogoMime === '') $mailLogoMime = 'image/png';
                    $mail->addEmbeddedImage($mailLogoPath, 'travscope-logo', basename($mailLogoPath), 'base64', $mailLogoMime);
                }
            }
        } catch (Throwable $logoError) {
            error_log('TRAVSCOPE quotation logo embed warning: ' . $logoError->getMessage());
        }

        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $plain;

        $mail->addStringAttachment(
            $pdfBytes,
            $pdfFileName,
            'base64',
            'application/pdf'
        );

        return $mail->send();

    } catch (Throwable $e) {
        error_log(
            'TRAVSCOPE CRM professional quotation email error: '
            . $e->getMessage()
        );

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| DIRECT CUSTOMER REPLY EMAIL
|--------------------------------------------------------------------------
*/

function crmSendDirectReplyEmail(
    string $to,
    string $subject,
    string $message,
    string $customerName,
    string $siteName
): bool {

    if (
        !class_exists(
            '\\PHPMailer\\PHPMailer\\PHPMailer'
        )
    ) {
        error_log(
            'TRAVSCOPE CRM reply: PHPMailer class is unavailable.'
        );
        return false;
    }

    try {

        $mail =
            new \PHPMailer\PHPMailer\PHPMailer(
                true
            );

        if (
            defined('SMTP_ENABLED')
            &&
            SMTP_ENABLED
        ) {
            $mail->isSMTP();

            $mail->Host =
                defined('SMTP_HOST')
                    ? SMTP_HOST
                    : 'smtp.gmail.com';

            $mail->SMTPAuth =
                defined('SMTP_AUTH')
                    ? (bool)SMTP_AUTH
                    : true;

            $mail->Username =
                defined('SMTP_USERNAME')
                    ? SMTP_USERNAME
                    : '';

            $mail->Password =
                defined('SMTP_PASSWORD')
                    ? SMTP_PASSWORD
                    : '';

            $mail->Port =
                defined('SMTP_PORT')
                    ? (int)SMTP_PORT
                    : 587;

            if (
                defined('SMTP_ENCRYPTION')
                &&
                trim(
                    (string)SMTP_ENCRYPTION
                ) !== ''
            ) {
                $mail->SMTPSecure =
                    SMTP_ENCRYPTION;
            }
        }

        $fromAddress =
            defined('MAIL_FROM_ADDRESS')
                ? MAIL_FROM_ADDRESS
                : (
                    defined('SMTP_USERNAME')
                        ? SMTP_USERNAME
                        : ''
                );

        $fromName =
            defined('MAIL_FROM_NAME')
                ? MAIL_FROM_NAME
                : $siteName;

        $mail->setFrom(
            $fromAddress,
            $fromName
        );

        if (
            defined('MAIL_REPLY_TO')
            &&
            trim(
                (string)MAIL_REPLY_TO
            ) !== ''
        ) {
            $mail->addReplyTo(
                MAIL_REPLY_TO,
                $fromName
            );
        }

        $mail->addAddress(
            $to,
            $customerName
        );

        $mail->CharSet =
            'UTF-8';

        $mail->isHTML(true);

        $mail->Subject =
            $subject;

        $safeCustomer =
            htmlspecialchars(
                $customerName !== ''
                    ? $customerName
                    : 'Customer',
                ENT_QUOTES,
                'UTF-8'
            );

        $safeMessage =
            nl2br(
                htmlspecialchars(
                    $message,
                    ENT_QUOTES,
                    'UTF-8'
                )
            );

        $safeSite =
            htmlspecialchars(
                $siteName,
                ENT_QUOTES,
                'UTF-8'
            );

        $mail->Body =
            '<div style="margin:0;background:#f3f6fa;padding:28px 12px;font-family:Arial,sans-serif;color:#172235">'
            . '<div style="max-width:660px;margin:auto;background:#fff;border:1px solid #dce5ee;border-radius:16px;overflow:hidden">'
            . '<div style="padding:22px 26px;background:#07192d;border-bottom:5px solid #0b74ff;color:#fff">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:1px;color:#9ed2ff">TRAVSCOPE CRM REPLY</div>'
            . '<div style="margin-top:6px;font-size:22px;font-weight:800">Message regarding your quotation</div>'
            . '</div>'
            . '<div style="padding:24px 26px">'
            . '<div style="font-size:16px;font-weight:800;color:#07192d">Dear ' . $safeCustomer . ',</div>'
            . '<div style="margin-top:14px;padding:17px 18px;border-radius:12px;background:#f7faff;border-left:5px solid #0b74ff;font-size:14px;line-height:1.75;color:#4f6074">'
            . $safeMessage
            . '</div>'
            . '<div style="margin-top:18px;font-size:13px;color:#6b7788">You can reply directly to this email if you have any questions or wish to confirm the quotation.</div>'
            . '</div>'
            . '<div style="padding:16px 26px;background:#07192d;color:#b9c9d9;font-size:12px">'
            . '<strong style="color:#fff">' . $safeSite . '</strong><br>Travel thoughtfully. Explore confidently.'
            . '</div>'
            . '</div>'
            . '</div>';

        $mail->AltBody =
            "Dear "
            . ($customerName !== ''
                ? $customerName
                : 'Customer')
            . ",\n\n"
            . $message
            . "\n\nYou can reply directly to this email if you have questions or wish to confirm the quotation.\n\n"
            . $siteName;

        return $mail->send();

    } catch (Throwable $e) {

        error_log(
            'TRAVSCOPE CRM direct reply email error: '
            . $e->getMessage()
        );

        return false;
    }
}



/*
|--------------------------------------------------------------------------
| EXACT ZENZ QUOTATION TEMPLATE -> PREVIEW + PDF
|--------------------------------------------------------------------------
|
| One shared HTML design is used for:
| 1. Admin PDF Preview / Print
| 2. Email PDF attachment
| 3. WhatsApp PDF download link
|
*/

function crmQuotationLocalImageDataUri(string $image): string
{
    $image = trim($image);

    if ($image === '' || preg_match('#^https?://#i', $image)) {
        return crmImageUrl($image);
    }

    $relative = ltrim($image, '/');
    $path = __DIR__ . '/' . $relative;

    if (!is_file($path)) {
        return crmImageUrl($image);
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $mime = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => '',
    };

    if ($mime === '') {
        return crmImageUrl($image);
    }

    $bytes = @file_get_contents($path);

    if ($bytes === false || $bytes === '') {
        return crmImageUrl($image);
    }

    return
        'data:'
        . $mime
        . ';base64,'
        . base64_encode($bytes);
}


function crmQuotationZenzHtml(
    array $version,
    array $snapshot,
    string $siteName,
    string $currencySymbol,
    int $leadId,
    bool $includeToolbar = true,
    bool $embedLocalImages = false
): string {

    global $pdo;

    $quotationBrand =
        isset($pdo)
        && $pdo instanceof PDO
            ? crmQuotationBrandDetails($pdo)
            : [
                'site_name' => $siteName,
                'header_name' => $siteName,
                'email' => '',
                'phone' => '',
                'website' => defined('BASE_URL')
                    ? rtrim((string)BASE_URL, '/')
                    : '',
                'address' => '',
                'logo' => '',
            ];

    $quotationLogo =
        trim(
            (string)(
                $quotationBrand['logo']
                ?? ''
            )
        );

    $quotationLogoUrl =
        $quotationLogo !== ''
            ? (
                $embedLocalImages
                    ? crmQuotationLocalImageDataUri(
                        $quotationLogo
                    )
                    : crmImageUrl(
                        $quotationLogo
                    )
            )
            : '';

    $quotationContactBits = [];

    foreach ([
        $quotationBrand['phone'] ?? '',
        $quotationBrand['email'] ?? '',
        $quotationBrand['website'] ?? '',
    ] as $quotationContactBit) {
        $quotationContactBit =
            trim(
                (string)$quotationContactBit
            );

        if ($quotationContactBit !== '') {
            $quotationContactBits[] =
                $quotationContactBit;
        }
    }

    $quotationContactLine =
        implode(
            ' • ',
            $quotationContactBits
        );
    $quotationHeaderName = trim((string)($quotationBrand['header_name'] ?? $siteName)) ?: $siteName;


    $qv = $version;
    $qs = $snapshot;

    $qImageRaw =
        (string)(
            $qs['main_image']
            ?? ''
        );

    $qImage =
        $embedLocalImages
            ? crmQuotationLocalImageDataUri($qImageRaw)
            : crmImageUrl($qImageRaw);

    $qItinerary =
        is_array(
            $qs['itinerary_days']
            ?? null
        )
            ? $qs['itinerary_days']
            : [];

    /*
    | Replace itinerary paths with embedded image data only for server-side PDF.
    */
    if ($embedLocalImages) {
        foreach ($qItinerary as &$quotationDay) {
            if (is_array($quotationDay)) {
                $quotationDay['image'] =
                    crmQuotationLocalImageDataUri(
                        (string)(
                            $quotationDay['image']
                            ?? ''
                        )
                    );
            }
        }
        unset($quotationDay);
    }

    $quotationReference = 'TSQ-'
        . str_pad((string)$leadId, 5, '0', STR_PAD_LEFT)
        . '-V'
        . str_pad((string)max(1, (int)($qv['version_number'] ?? 1)), 2, '0', STR_PAD_LEFT);
    $quotationPreparedDate = !empty($qv['created_at'])
        ? date('d M Y', strtotime((string)$qv['created_at']))
        : date('d M Y');

    $quotationCredentials = [];
    foreach ((array)($quotationBrand['credentials'] ?? []) as $credential) {
        if (!is_array($credential)) continue;
        $credentialLabel = trim((string)($credential['label'] ?? ''));
        $credentialLogoRaw = trim((string)($credential['logo'] ?? ''));
        if ($credentialLabel === '' || $credentialLogoRaw === '') continue;
        $quotationCredentials[] = [
            'label' => $credentialLabel,
            'logo' => $embedLocalImages
                ? crmQuotationLocalImageDataUri($credentialLogoRaw)
                : crmImageUrl($credentialLogoRaw),
        ];
    }

    $quotationFeatureImage = $qImage;
    $quotationGallery = [];
    $seenQuotationImages = [];
    $addQuotationImage = static function (string $image) use (&$quotationGallery, &$seenQuotationImages): void {
        $image = trim($image);
        if ($image === '' || isset($seenQuotationImages[$image])) return;
        $seenQuotationImages[$image] = true;
        $quotationGallery[] = $image;
    };

    if ($quotationFeatureImage !== '') {
        $addQuotationImage($quotationFeatureImage);
    }
    foreach ($qItinerary as $quotationDay) {
        if (!is_array($quotationDay)) continue;
        $dayImage = trim((string)($quotationDay['image'] ?? ''));
        if ($dayImage !== '') $addQuotationImage($dayImage);
    }
    if ($quotationFeatureImage === '' && $quotationGallery) {
        $quotationFeatureImage = (string)$quotationGallery[0];
    }
    $quotationGallery = array_values(array_filter(
        array_slice($quotationGallery, 1, 4),
        static fn(string $image): bool => $image !== ''
    ));

    $qPrices =
        is_array(
            $qs['date_prices']
            ?? null
        )
            ? $qs['date_prices']
            : [];

    $qHotels =
        is_array(
            $qs['hotel_allocations']
            ?? null
        )
            ? $qs['hotel_allocations']
            : [];

    $qTransport =
        is_array(
            $qs['transport_allocations']
            ?? null
        )
            ? $qs['transport_allocations']
            : [];

    $qFlights = is_array($qs['flight_suggestions'] ?? null) ? $qs['flight_suggestions'] : [];
    $qTrains = is_array($qs['train_suggestions'] ?? null) ? $qs['train_suggestions'] : [];

    $qServiceSectionCount = ($qHotels ? 1 : 0) + ($qTransport ? 1 : 0) + ($qFlights ? 1 : 0) + ($qTrains ? 1 : 0);

    ob_start();
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Quotation <?= (int)$qv['version_number']; ?> | <?= e($siteName); ?></title>
<style>
*{box-sizing:border-box}
:root{
    --ink:#132033;
    --muted:#6e7b8f;
    --navy:#07192d;
    --blue:#0b74ff;
    --cyan:#00b8ff;
    --green:#07885f;
    --purple:#6953c7;
    --gold:#c47a0a;
    --line:#dbe5ef;
    --paper:#ffffff;
    --bg:#eef3f8;
}
body{
    margin:0;
    background:
        radial-gradient(circle at top right,rgba(11,116,255,.10),transparent 32%),
        linear-gradient(180deg,#eef5fb,#f7f9fc);
    color:var(--ink);
    font:14px/1.65 Arial,sans-serif;
}
.preview-toolbar{
    position:sticky;
    top:0;
    z-index:20;
    min-height:62px;
    padding:10px 18px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:10px;
    background:rgba(7,25,45,.96);
    box-shadow:0 8px 24px rgba(7,25,45,.16);
}
.preview-toolbar button,
.preview-toolbar a{
    min-height:40px;
    padding:0 16px;
    border:0;
    border-radius:9px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    text-decoration:none;
    cursor:pointer;
    font-weight:800;
}
.preview-toolbar button{
    background:var(--blue);
    color:#fff;
}
.preview-toolbar a{
    background:#fff;
    color:var(--navy);
}
.sheet{
    width:min(930px,94%);
    margin:28px auto 45px;
    overflow:hidden;
    border-radius:20px;
    background:var(--paper);
    box-shadow:0 25px 80px rgba(7,25,45,.16);
}
.hero{
    position:relative;
    padding:36px;
    overflow:hidden;
    color:#fff;
    background:
        linear-gradient(135deg,rgba(7,25,45,.98),rgba(11,116,255,.92));
}
.hero:after{
    content:"";
    position:absolute;
    width:290px;
    height:290px;
    right:-110px;
    top:-150px;
    border-radius:50%;
    background:rgba(255,255,255,.09);
}
.hero-grid{
    position:relative;
    z-index:2;
    display:grid;
    grid-template-columns:minmax(0,1.25fr) minmax(250px,.75fr);
    gap:28px;
    align-items:center;
}
.hero-kicker{
    display:inline-flex;
    padding:7px 11px;
    border-radius:30px;
    background:rgba(255,255,255,.13);
    color:#cce8ff;
    font-size:10px;
    font-weight:800;
    letter-spacing:1px;
}
.hero h1{
    margin:12px 0 8px;
    font-size:34px;
    line-height:1.15;
}
.hero-desc{
    max-width:570px;
    color:#deefff;
    font-size:14px;
}
.hero img{
    width:100%;
    height:205px;
    object-fit:cover;
    border:3px solid rgba(255,255,255,.18);
    border-radius:16px;
    box-shadow:0 12px 35px rgba(0,0,0,.20);
}
.content{
    padding:30px;
}
.intro-strip{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:20px;
    padding:16px 18px;
    border:1px solid #cfe0f5;
    border-radius:13px;
    background:linear-gradient(135deg,#f4f9ff,#ffffff);
}
.intro-strip strong{
    color:var(--navy);
    font-size:16px;
}
.intro-strip span{
    color:var(--muted);
    font-size:12px;
}
.meta{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:11px;
    margin-bottom:24px;
}
.meta-card{
    min-height:96px;
    padding:15px;
    border-radius:13px;
    border:1px solid var(--line);
}
.meta-card:nth-child(1){background:#eef6ff;border-color:#cfe2ff}
.meta-card:nth-child(2){background:#f4efff;border-color:#ded2ff}
.meta-card:nth-child(3){background:#fff6e8;border-color:#ffe1b9}
.meta-card:nth-child(4){background:#edf9f3;border-color:#c9ead9}
.meta-card:nth-child(5){background:#fff1f4;border-color:#ffd2da}
.meta-card:nth-child(6){background:#eafaf2;border-color:#c5ead7}
.meta-card small{
    display:block;
    color:#728095;
    font-size:10px;
    font-weight:800;
    letter-spacing:.5px;
    text-transform:uppercase;
}
.meta-card strong{
    display:block;
    margin-top:7px;
    color:var(--navy);
    font-size:15px;
}
.meta-card .price{
    color:var(--green);
    font-size:23px;
}
.section{
    margin-top:26px;
}
.section-head{
    display:flex;
    align-items:center;
    gap:10px;
    margin-bottom:13px;
}
.section-icon{
    width:34px;
    height:34px;
    border-radius:10px;
    display:grid;
    place-items:center;
    background:#eaf4ff;
    color:var(--blue);
    font-weight:800;
}
.section h2{
    margin:0;
    color:var(--navy);
    font-size:19px;
}
.prose{
    padding:17px 18px;
    border:1px solid var(--line);
    border-radius:12px;
    background:#fbfcfe;
    color:#536277;
}
.pricing{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    overflow:hidden;
    border:1px solid var(--line);
    border-radius:12px;
}
.pricing th,
.pricing td{
    padding:11px 12px;
    border-right:1px solid var(--line);
    border-bottom:1px solid var(--line);
    text-align:left;
}
.pricing th{
    background:var(--navy);
    color:#fff;
    font-size:11px;
}
.pricing th:last-child,
.pricing td:last-child{border-right:0}
.pricing tbody tr:last-child td{border-bottom:0}
.pricing tbody tr:nth-child(even) td{background:#f8fafc}
.day{
    display:block;
    padding:15px 17px 14px;
    margin-bottom:13px;
    overflow:hidden;
    border:1px solid #dbe5ef;
    border-radius:13px;
    background:#fff;
    box-shadow:0 6px 18px rgba(7,25,45,.045);
    page-break-inside:auto;
}
.day-heading{
    display:table;
    width:100%;
    margin:0 0 9px;
    page-break-after:avoid;
}
.day-badge{
    display:table-cell;
    width:48px;
    vertical-align:top;
    padding-top:1px;
}
.day-badge span{
    display:inline-block;
    min-width:39px;
    padding:6px 6px 5px;
    border-radius:10px;
    background:#eaf4ff;
    color:#0b67c7;
    font-size:9px;
    line-height:1.15;
    font-weight:900;
    text-align:center;
    letter-spacing:.35px;
}
.day-title-wrap{
    display:table-cell;
    vertical-align:middle;
}
.day-kicker{
    display:block;
    margin:0 0 2px;
    color:#1680b9;
    font-size:8.5px;
    font-weight:900;
    line-height:1.25;
    letter-spacing:.6px;
    text-transform:uppercase;
}
.day-title{
    display:block;
    color:var(--navy);
    font-size:15px;
    font-weight:900;
    line-height:1.34;
}
.day-flow{
    display:block;
    min-height:0;
}
.day-flow:after{
    content:"";
    display:table;
    clear:both;
}
.day-image-wrap{
    float:left;
    width:39%;
    max-width:255px;
    min-height:0;
    max-height:none;
    margin:0 14px 7px 0;
    display:block;
    overflow:hidden;
    background:#f1f5f9;
    border:0;
    border-radius:9px;
}
.day-image-wrap img{
    display:block;
    width:100%;
    height:auto;
    max-height:190px;
    object-fit:contain;
    object-position:center center;
    background:#f1f5f9;
}
.day-content{
    padding:0;
}
.day p{
    margin:0;
    color:#4f6074;
    font-size:12.2px;
    line-height:1.62;
    text-align:justify;
    text-justify:inter-word;
    text-align-last:left;
    overflow-wrap:break-word;
    widows:3;
    orphans:3;
}
.day-route{
    clear:both;
    margin-top:9px;
    padding-top:7px;
    border-top:1px solid #e7edf4;
    color:#385b78;
    font-size:10px;
    font-weight:700;
    line-height:1.45;
}
.highlight-section .prose{border-left:5px solid var(--blue);background:#f3f8ff}
.inclusion-section .prose{border-left:5px solid var(--green);background:#f0fbf5}
.exclusion-section .prose{border-left:5px solid #d33f53;background:#fff5f6}
.terms-section .prose{border-left:5px solid var(--gold);background:#fff9ef}
.notes-section .prose{border-left:5px solid var(--purple);background:#f7f4ff}
.document-list{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:8px;
}
.document-list-item{
    display:grid;
    grid-template-columns:27px minmax(0,1fr);
    gap:9px;
    align-items:start;
    min-height:46px;
    padding:10px 11px;
    border:1px solid var(--line);
    border-radius:10px;
    background:#fff;
    color:#4e6075;
    font-size:11.5px;
    line-height:1.48;
    page-break-inside:avoid;
}
.document-list-mark{
    width:24px;
    height:24px;
    border-radius:8px;
    display:grid;
    place-items:center;
    font-size:10px;
    font-weight:900;
}
.highlight-section .document-list-item{border-color:#d7e7f8;background:#fbfdff}
.highlight-section .document-list-mark{background:#eaf4ff;color:#116ec3}
.inclusion-section .document-list-item{border-color:#d8eee4;background:#fbfefa}
.inclusion-section .document-list-mark{background:#e8f7f0;color:#0b9660}
.exclusion-section .document-list-item{border-color:#f2dce0;background:#fffdfd}
.exclusion-section .document-list-mark{background:#fff0f2;color:#d84959}
.section-intro{
    margin:-3px 0 9px 44px;
    color:#7b899a;
    font-size:10px;
    line-height:1.4;
}

.footer{
    margin-top:30px;
    padding:20px;
    border-radius:13px;
    text-align:center;
    background:var(--navy);
    color:#bbcad9;
}
.footer strong{
    display:block;
    margin-bottom:4px;
    color:#fff;
    font-size:15px;
}
@media print{
    @page{size:A4;margin:10mm}
    body{background:#fff}
    .preview-toolbar{display:none!important}
    .sheet{
        width:100%;
        margin:0;
        border-radius:0;
        box-shadow:none;
    }
    .hero{
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
    .meta-card,
    .pricing th,
    .footer,
    .highlight-section .prose,
    .inclusion-section .prose,
    .exclusion-section .prose,
    .terms-section .prose,
    .notes-section .prose{
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
    .quotation-document-header{padding:3mm 6mm!important;border-top-width:3px!important;gap:5mm!important}
    .hero{padding:7mm 8mm!important}
    .hero h1{font-size:24px;line-height:1.18;margin:8px 0 6px}
    .hero-desc{font-size:11px;line-height:1.45}
    .hero img{height:145px;border-radius:10px;box-shadow:none}
    .content{padding:7mm 7mm 5mm}
    .intro-strip{margin-bottom:12px;padding:10px 12px;border-radius:9px}
    .meta{gap:6px;margin-bottom:14px}
    .meta-card{min-height:0;padding:9px 10px;border-radius:9px}
    .meta-card strong{margin-top:3px;font-size:12px}
    .meta-card .price{font-size:17px}
    .section{margin-top:16px}
    .section-head{margin-bottom:8px}
    .section-icon{width:27px;height:27px;border-radius:7px;font-size:10px}
    .section h2{font-size:15px}
    .prose{padding:11px 12px;border-radius:8px;font-size:11px;line-height:1.5}
    .document-list{gap:5px}
    .document-list-item{min-height:0;padding:7px 8px;grid-template-columns:22px minmax(0,1fr);gap:6px;border-radius:7px;font-size:9.4pt;line-height:1.38}
    .document-list-mark{width:20px;height:20px;border-radius:6px;font-size:8px}
    .section-intro{margin:-2px 0 6px 35px;font-size:8.5pt}
    .pricing th,.pricing td{padding:7px 8px;font-size:10px;line-height:1.35}
    .pricing thead{display:table-header-group}
    .pricing tr{page-break-inside:avoid}
    .day{margin-bottom:9px;padding:10px 11px 9px;border-radius:9px;box-shadow:none}
    .day-heading{margin-bottom:6px}
    .day-badge{width:42px}
    .day-badge span{min-width:34px;padding:5px 5px 4px;font-size:8px;border-radius:7px}
    .day-title{font-size:12.5px;line-height:1.3}
    .day-kicker{font-size:7.5px}
    .day-image-wrap{width:38%;max-width:58mm;margin:0 4mm 2mm 0;border-radius:6px}
    .day-image-wrap img{max-height:38mm}
    .day p{font-size:9.7pt;line-height:1.47}
    .day-route{margin-top:6px;padding-top:5px;font-size:8.7pt}
    .footer{margin-top:18px;padding:12px;border-radius:8px;font-size:10px}
}
@media(max-width:700px){
    .hero-grid,.meta{grid-template-columns:1fr}
    .hero img{height:220px}
    .day{padding:12px}
    .day-image-wrap{float:none;width:100%;max-width:none;min-height:0;max-height:260px;margin:0 0 10px}
    .day-image-wrap img{max-height:260px}
    .day-content{padding:0}
    .day p{text-align:left;font-size:12px;line-height:1.58}
    .intro-strip{align-items:flex-start;flex-direction:column}
}

.version-actions .quotation-reply-action{
    border-color:#cfc8ff;
    background:#f3efff;
    color:#5e45bd;
    cursor:pointer;
}
.version-actions .quotation-reply-action:hover{
    border-color:#6e55d5;
    background:#6e55d5;
    color:#fff;
}

.crm-reply-modal{
    position:fixed;
    inset:0;
    z-index:6500;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:rgba(4,18,34,.76);
}
.crm-reply-modal.open{display:flex}
.crm-reply-dialog{
    width:min(650px,96vw);
    max-height:92vh;
    overflow:auto;
    border-radius:16px;
    background:#fff;
    box-shadow:0 28px 90px rgba(0,0,0,.28);
}
.crm-reply-head{
    padding:18px 20px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    border-bottom:1px solid var(--border);
    background:linear-gradient(135deg,#f7f4ff,#fff);
}
.crm-reply-head h3{
    color:var(--navy);
    font:800 18px 'Manrope',sans-serif;
}
.crm-reply-head p{
    margin-top:4px;
    color:var(--muted);
    font-size:11px;
}
.crm-reply-close{
    width:38px;
    height:38px;
    border:0;
    border-radius:9px;
    background:#edf2f7;
    color:#526175;
    cursor:pointer;
}
.crm-reply-body{padding:18px 20px}
.crm-reply-customer{
    padding:12px 14px;
    margin-bottom:14px;
    border:1px solid #ddd7ff;
    border-radius:10px;
    background:#f8f6ff;
}
.crm-reply-customer strong{
    display:block;
    color:#392b79;
}
.crm-reply-customer span{
    display:block;
    margin-top:3px;
    color:#716b8c;
    font-size:11px;
}
.crm-reply-templates{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
    margin-bottom:10px;
}
.crm-reply-template{
    padding:7px 10px;
    border:1px solid var(--border);
    border-radius:20px;
    background:#fff;
    color:#566579;
    font-size:10px;
    font-weight:800;
    cursor:pointer;
}
.crm-reply-template:hover{
    border-color:#6e55d5;
    color:#5e45bd;
    background:#f7f4ff;
}
.crm-reply-text{
    width:100%;
    min-height:155px;
    padding:12px 13px;
    border:1px solid var(--border);
    border-radius:10px;
    resize:vertical;
    outline:none;
}
.crm-reply-text:focus{
    border-color:#9a89eb;
    box-shadow:0 0 0 3px rgba(110,85,213,.08);
}
.crm-reply-actions{
    margin-top:14px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.crm-reply-send{
    background:#6e55d5;
}
.crm-reply-whatsapp{
    background:#eafaf2;
    color:#087952;
    border:1px solid #bde7d2;
}
.crm-reply-inbox{
    background:#edf5ff;
    color:#0b67c7;
    border:1px solid #c9ddf7;
}


/* ========================================================================
   QUOTATION VERSION EMAIL CONVERSATION
   ======================================================================== */

.crm-conversation-dialog{
    width:min(820px,96vw);
    height:min(850px,92vh);
    display:flex;
    flex-direction:column;
    overflow:hidden;
}

.crm-conversation-customer{
    padding:12px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    border-bottom:1px solid var(--border);
    background:#f8fafc;
}

.crm-conversation-customer strong{
    display:block;
    color:var(--navy);
    font-size:13px;
}

.crm-conversation-customer span{
    display:block;
    margin-top:2px;
    color:var(--muted);
    font-size:10px;
}

.crm-conversation-status{
    background:#fff8e8;
    color:#93650b;
    font-size:10px;
    line-height:1.5;
}

.crm-conversation-status:not(:empty){
    padding:8px 18px;
    border-bottom:1px solid #f0dfb5;
}

.crm-conversation-thread{
    flex:1;
    min-height:300px;
    overflow:auto;
    padding:20px 18px;
    background:
        radial-gradient(circle at top right,rgba(11,116,255,.06),transparent 28%),
        #f3f6fa;
}

.crm-message-row{
    display:flex;
    margin-bottom:14px;
}

.crm-message-row.incoming{
    justify-content:flex-start;
}

.crm-message-row.outgoing{
    justify-content:flex-end;
}

.crm-message-bubble{
    width:min(82%,620px);
    padding:13px 15px;
    border-radius:15px;
    box-shadow:0 4px 14px rgba(7,25,45,.06);
}

.crm-message-row.incoming .crm-message-bubble{
    border:1px solid #d8e2ec;
    border-bottom-left-radius:5px;
    background:#fff;
}

.crm-message-row.outgoing .crm-message-bubble{
    border:1px solid #c7dcff;
    border-bottom-right-radius:5px;
    background:#eaf3ff;
}

.crm-message-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:5px;
}

.crm-message-top strong{
    color:var(--navy);
    font-size:11px;
}

.crm-message-top span{
    color:#8a96a6;
    font-size:9px;
}

.crm-message-subject{
    margin-bottom:7px;
    color:#4c5d72;
    font-size:10px;
    font-weight:800;
}

.crm-message-body{
    color:#425267;
    font-size:12px;
    line-height:1.65;
    overflow-wrap:anywhere;
}

.crm-conversation-compose{
    padding:14px 18px 18px;
    border-top:1px solid var(--border);
    background:#fff;
}

.crm-compose-label{
    margin-bottom:7px;
    color:var(--navy);
    font-size:11px;
    font-weight:800;
}

.crm-conversation-compose .crm-reply-text{
    min-height:105px;
}

.crm-conversation-empty,
.crm-conversation-loading,
.crm-conversation-error{
    min-height:230px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:24px;
    text-align:center;
    color:var(--muted);
}

.crm-conversation-empty i,
.crm-conversation-loading i,
.crm-conversation-error i{
    font-size:24px;
    color:var(--blue);
}

.crm-conversation-empty strong{
    color:var(--navy);
    font-size:13px;
}

.crm-conversation-empty span{
    max-width:430px;
    font-size:11px;
    line-height:1.6;
}

.crm-conversation-error{
    color:#b7394b;
}

@media(max-width:650px){
    .crm-conversation-dialog{
        width:100%;
        height:96vh;
        border-radius:12px;
    }

    .crm-message-bubble{
        width:92%;
    }

    .crm-conversation-customer{
        align-items:flex-start;
        flex-direction:column;
    }
}


/* =========================================================
   AUTOMATIC PACKAGE COSTING + SUPPLIER COMMISSION
========================================================= */
.crm-child-master-box{
    margin-top:12px;
    padding:12px;
    border:1px solid var(--border);
    border-radius:10px;
    background:#fafbfd;
}
.crm-child-rate-list{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
    margin-top:8px;
}
.crm-child-rate-pill{
    padding:7px 10px;
    border:1px solid #cfe0f7;
    border-radius:20px;
    background:#edf5ff;
    color:#245f9e;
    font-size:11px;
    font-weight:800;
}
.crm-child-age-inputs{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(145px,1fr));
    gap:8px;
}
.crm-child-age-inputs .control{
    min-height:40px;
}
.crm-costing-summary{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;
    margin:14px 0;
}
.crm-cost-card{
    padding:13px;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
}
.crm-cost-card span,
.crm-cost-card small{
    display:block;
}
.crm-cost-card span{
    color:var(--muted);
    font-size:10px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.4px;
}
.crm-cost-card strong{
    display:block;
    margin:5px 0 2px;
    color:var(--navy);
    font:800 19px 'Manrope',sans-serif;
}
.crm-cost-card small{
    color:var(--muted);
    font-size:9px;
}
.crm-cost-card.primary{
    border-color:#9ac8ff;
    background:#edf5ff;
}
.crm-cost-card.primary strong{
    color:var(--blue);
}
.crm-supplier-costing{
    margin-top:14px;
    padding:14px;
    border:1px solid #d8e5f3;
    border-radius:12px;
    background:#f8fbff;
}
.crm-costing-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:12px;
}
.crm-costing-title span{
    color:var(--blue);
    font-size:9px;
    font-weight:900;
    letter-spacing:.7px;
}
.crm-costing-title h4{
    margin-top:3px;
    color:var(--navy);
    font:800 15px 'Manrope',sans-serif;
}
.crm-costing-title>i{
    width:38px;
    height:38px;
    display:grid;
    place-items:center;
    border-radius:10px;
    background:#eafaf2;
    color:var(--green);
}
.crm-final-selling{
    margin-top:14px;
    padding:15px 16px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    background:linear-gradient(135deg,#07192d,#0b74ff);
    color:#fff;
}
.crm-final-selling span,
.crm-final-selling small{
    display:block;
}
.crm-final-selling span{
    font-size:10px;
    font-weight:900;
    letter-spacing:.7px;
}
.crm-final-selling small{
    margin-top:3px;
    color:#dcecff;
    font-size:10px;
}
.crm-final-selling strong{
    font:800 25px 'Manrope',sans-serif;
}
@media(max-width:900px){
    .crm-costing-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:560px){
    .crm-costing-summary{grid-template-columns:1fr}
    .crm-final-selling{align-items:flex-start;flex-direction:column}
}

</style>

<style id="travscope-simple-pricing-ui">
.crm-simple-price-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:14px 0}.crm-simple-price-card{border:1px solid #dfe7f2;border-radius:14px;padding:14px 16px;background:#fff;box-shadow:0 4px 14px rgba(15,23,42,.04)}.crm-simple-price-card span{display:block;font-size:11px;font-weight:900;letter-spacing:.06em;margin-bottom:6px}.crm-simple-price-card strong{display:block;font-size:19px;line-height:1.25;margin-bottom:4px}.crm-simple-price-card small{color:#64748b}.crm-simple-price-card.blue{background:#eff6ff;border-color:#bfdbfe}.crm-simple-price-card.blue span,.crm-simple-price-card.blue strong{color:#1d4ed8}.crm-simple-price-card.amber{background:#fffbeb;border-color:#fde68a}.crm-simple-price-card.amber span,.crm-simple-price-card.amber strong{color:#a16207}.crm-simple-price-card.green{background:#ecfdf5;border-color:#a7f3d0}.crm-simple-price-card.green span,.crm-simple-price-card.green strong{color:#047857}.crm-calc-details,.crm-advanced-costing{border:1px solid #e2e8f0;border-radius:12px;background:#fff;margin:12px 0;overflow:hidden}.crm-calc-details>summary,.crm-advanced-costing>summary{cursor:pointer;padding:12px 14px;font-weight:800;color:#334155;list-style:none}.crm-calc-details>summary::-webkit-details-marker,.crm-advanced-costing>summary::-webkit-details-marker{display:none}.crm-calc-details>summary i,.crm-advanced-costing>summary i{margin-right:7px;color:#6366f1}.crm-calc-details[open]>summary,.crm-advanced-costing[open]>summary{background:#f8fafc;border-bottom:1px solid #e2e8f0}.crm-calc-details .crm-costing-summary{padding:12px}.crm-advanced-costing .crm-supplier-costing{margin:0;border:0;border-radius:0;box-shadow:none}.crm-price-note{margin:10px 0 0;padding:10px 12px;border-radius:10px;background:#f8fafc;color:#475569;font-size:12px;font-weight:700}@media(max-width:900px){.crm-simple-price-summary{grid-template-columns:1fr}}

/* =========================================================
   QUOTATION ITINERARY — CLEAR DAY MANAGEMENT
========================================================= */
.itinerary-help-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
}
.itinerary-auto-badge{
    flex:0 0 auto;
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:7px 10px;
    border:1px solid #bfdbfe;
    border-radius:999px;
    background:#eff6ff;
    color:#1d4ed8;
    font-size:11px;
    font-weight:800;
}
.itinerary-edit-card{
    transition:
        border-color .2s ease,
        box-shadow .2s ease,
        transform .2s ease;
}
.itinerary-card-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}
.itinerary-order-tools{
    display:flex;
    align-items:center;
    gap:7px;
    flex-wrap:wrap;
}
.itinerary-order-button,
.itinerary-move-to{
    min-height:34px;
    border:1px solid #d7e2ef;
    border-radius:8px;
    background:#fff;
    color:#334155;
    font:700 11px 'Manrope',sans-serif;
}
.itinerary-order-button{
    padding:0 10px;
    cursor:pointer;
}
.itinerary-order-button:hover:not(:disabled){
    border-color:#93c5fd;
    background:#eff6ff;
    color:#1d4ed8;
}
.itinerary-order-button:disabled{
    opacity:.38;
    cursor:not-allowed;
}
.itinerary-move-to{
    padding:0 9px;
    cursor:pointer;
}
.itinerary-just-moved{
    border-color:#60a5fa !important;
    box-shadow:0 0 0 3px rgba(59,130,246,.12);
    transform:translateY(-2px);
}
@media(max-width:700px){
    .itinerary-help-row{
        align-items:flex-start;
        flex-direction:column;
    }
    .itinerary-auto-badge{
        align-self:flex-start;
    }
    .itinerary-card-head{
        align-items:flex-start;
        flex-direction:column;
    }
    .itinerary-order-tools{
        width:100%;
    }
    .itinerary-move-to{
        flex:1;
        min-width:140px;
    }
}


/* =========================================================
   SELECTED SUPPLIER COSTING — COMPLETE / SERVICE / HYBRID
========================================================= */
.crm-approved-supplier-cost{
    border:1px solid #c7d2fe;
    background:linear-gradient(135deg,#eef2ff,#f8fafc 55%,#ecfdf5);
    border-radius:14px;
    padding:12px;
}
.crm-approved-cost-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:10px;
}
.crm-approved-cost-head span{
    display:block;
    color:#6366f1;
    font-size:9px;
    font-weight:900;
    letter-spacing:.5px;
}
.crm-approved-cost-head strong{
    display:block;
    margin-top:2px;
    color:#172033;
    font-size:14px;
}
.crm-approved-total{
    flex:0 0 auto;
    padding:8px 10px;
    border-radius:10px;
    background:#0f172a;
    color:#fff;
    font-size:14px;
    font-weight:900;
}
.crm-approved-cost-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:8px;
}
.crm-approved-cost-grid>div{
    padding:8px 9px;
    border:1px solid #e2e8f0;
    border-radius:10px;
    background:#fff;
}
.crm-approved-cost-grid>div.total{
    border-color:#a7f3d0;
    background:#ecfdf5;
}
.crm-approved-cost-grid span{
    display:block;
    color:#94a3b8;
    font-size:8px;
    font-weight:900;
    letter-spacing:.35px;
}
.crm-approved-cost-grid strong{
    display:block;
    margin-top:3px;
    color:#172033;
    font-size:11px;
}
.crm-selected-quote-details{
    margin-top:9px;
    border-top:1px dashed #cbd5e1;
    padding-top:7px;
}
.crm-selected-quote-details summary{
    cursor:pointer;
    color:#475569;
    font-size:10px;
    font-weight:800;
}
.crm-selected-quote-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-top:6px;
    padding:7px 8px;
    border:1px solid #e2e8f0;
    border-radius:9px;
    background:#fff;
}
.crm-selected-quote-row strong,
.crm-selected-quote-row small{
    display:block;
}
.crm-selected-quote-row small{
    margin-top:2px;
    color:#64748b;
    font-size:9px;
}
.crm-selected-quote-row b{
    flex:0 0 auto;
    font-size:11px;
}
.crm-costing-warning{
    display:flex;
    align-items:flex-start;
    gap:7px;
    margin-bottom:9px;
    padding:8px 9px;
    border:1px solid #fecaca;
    border-radius:9px;
    background:#fff1f2;
    color:#b91c1c;
    font-size:10px;
    font-weight:800;
}
.crm-manual-supplier-fallback{
    padding:10px;
    border:1px dashed #cbd5e1;
    border-radius:11px;
    background:#f8fafc;
}
@media(max-width:760px){
    .crm-approved-cost-head{
        align-items:flex-start;
        flex-direction:column;
    }
    .crm-approved-cost-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .crm-selected-quote-row{
        align-items:flex-start;
        flex-direction:column;
    }
}


/* V24.96 — print-stable international quotation presentation */
.quotation-document-header{
    padding:14px 28px;
    background:#fff;
    border-top:5px solid #07192d;
    border-bottom:1px solid #dbe5ef;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:22px;
}
.quotation-brand-lockup{display:flex;align-items:center;gap:18px;min-width:0}
.quotation-brand-lockup img{display:block;width:2.65in;max-width:2.65in;max-height:.92in;height:auto;object-fit:contain;object-position:left center}
.quotation-brand-copy{min-width:0}
.quotation-brand-name{font-size:14px;font-weight:900;color:#07192d}
.quotation-brand-contact{margin-top:4px;color:#64748b;font-size:9.5px;line-height:1.45}
.quotation-brand-address{margin-top:3px;max-width:430px;color:#8090a3;font-size:8.8px;line-height:1.45}
.quotation-ref-block{text-align:right;flex:0 0 auto}
.quotation-ref-block small{display:block;color:#0b67c7;font-size:8px;font-weight:900;letter-spacing:.7px;text-transform:uppercase}
.quotation-ref-block strong{display:block;margin-top:3px;color:#07192d;font-size:13px;letter-spacing:.25px}
.quotation-ref-block span{display:block;margin-top:2px;color:#7c8999;font-size:8.5px}

.quote-hero{padding:0;background:#07192d;color:#fff;overflow:hidden}
.quote-hero-copy{padding:27px 30px 20px;background:linear-gradient(125deg,#07192d 0%,#0a315d 56%,#0b74ff 135%)}
.quote-hero-kicker{display:inline-flex;padding:5px 9px;border-radius:999px;background:rgba(255,255,255,.12);color:#bfe2ff;font-size:8.5px;font-weight:900;letter-spacing:.8px;text-transform:uppercase}
.quote-hero h1{margin:9px 0 7px;max-width:790px;font-size:31px;line-height:1.15;letter-spacing:-.45px}
.quote-hero-desc{max-width:790px;color:#d9e9f7;font-size:12.5px;line-height:1.6;text-align:justify;text-justify:inter-word}
.quote-feature-image{position:relative;background:#dce7f2;overflow:hidden}
.quote-feature-image img{display:block;width:100%;height:330px;object-fit:cover;object-position:center 48%}
.quote-feature-caption{position:absolute;left:18px;bottom:16px;padding:7px 10px;border-radius:999px;background:rgba(7,25,45,.78);backdrop-filter:blur(5px);color:#fff;font-size:9px;font-weight:800}

.customer-reference-panel{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(230px,.55fr);gap:12px;margin-bottom:14px;padding:15px;border:1px solid #bcd8f5;border-radius:13px;background:linear-gradient(135deg,#f0f7ff,#fff)}
.customer-reference-main{display:grid;grid-template-columns:auto minmax(0,1fr);gap:11px;align-items:center}
.customer-avatar{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#0b74ff;color:#fff;font-size:17px;font-weight:900;box-shadow:0 7px 18px rgba(11,116,255,.18)}
.customer-reference-main small,.customer-reference-meta small{display:block;color:#728095;font-size:8px;font-weight:900;letter-spacing:.6px;text-transform:uppercase}
.customer-reference-main strong{display:block;margin-top:2px;color:#07192d;font-size:17px;line-height:1.22}
.customer-contact-line{margin-top:4px;color:#52657b;font-size:10px;line-height:1.5;overflow-wrap:anywhere}
.customer-reference-meta{display:flex;flex-direction:column;justify-content:center;padding-left:14px;border-left:1px solid #d6e6f5}
.customer-reference-meta strong{display:block;margin-top:2px;color:#0b67c7;font-size:14px}
.customer-reference-meta span{margin-top:5px;color:#68788c;font-size:9px;line-height:1.45}

.quote-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin-bottom:18px}
.quote-summary-card{padding:12px 13px;border:1px solid #e0e8f0;border-radius:11px;background:#fff}
.quote-summary-card small{display:block;color:#7b899a;font-size:8px;font-weight:900;letter-spacing:.55px;text-transform:uppercase}
.quote-summary-card strong{display:block;margin-top:5px;color:#15283f;font-size:12.5px;line-height:1.35}
.quote-summary-card.price-card{background:#ecfbf4;border-color:#c8ebdb}
.quote-summary-card.price-card strong{color:#07885f;font-size:19px}
.overview-prose{text-align:justify;text-justify:inter-word;line-height:1.72;color:#45586e}

.journey-gallery{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}
.journey-gallery figure{margin:0;overflow:hidden;border:1px solid #dfe8f1;border-radius:9px;background:#f3f6fa;page-break-inside:avoid}
.journey-gallery img{display:block;width:100%;height:112px;object-fit:cover}
.journey-gallery figcaption{padding:6px 7px;color:#65758a;font-size:8px;line-height:1.3}

.quotation-credentials{margin-top:24px;padding:14px 16px;border:1px solid #dfe7ef;border-radius:12px;background:#fbfcfe;page-break-inside:avoid}
.quotation-credentials-title{margin-bottom:10px;color:#728095;font-size:8.5px;font-weight:900;letter-spacing:.75px;text-transform:uppercase;text-align:center}
.quotation-credentials-grid{display:block;text-align:center;font-size:0;line-height:0}
.quotation-credential{display:inline-block;vertical-align:top;width:110px;min-width:0;max-width:110px;margin:3px;padding:5px 6px;border:1px solid #e3eaf1;border-radius:9px;background:#fff;text-align:center;font-size:8px;line-height:1.3;break-inside:avoid;page-break-inside:avoid}
.quotation-credential img{display:block;width:auto;height:auto;max-width:45px;max-height:24px;margin:0 auto 4px}
.quotation-credential span{display:block;color:#516176;font-size:8px;font-weight:800;line-height:1.25;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-word}

.quotation-company-footer{margin-top:20px;padding:15px 18px;border-radius:12px;background:#07192d;color:#bac8d7;text-align:center;page-break-inside:avoid}
.quotation-company-footer strong{display:block;color:#fff;font-size:14px}
.quotation-company-footer .footer-address{margin-top:5px;font-size:8.8px;line-height:1.5}
.quotation-company-footer .footer-contact{margin-top:4px;color:#dbe8f4;font-size:8.8px;line-height:1.5}
.quotation-company-footer .footer-ref{margin-top:7px;color:#8fbce5;font-size:8px;font-weight:800;letter-spacing:.35px}

@media print{
    @page{size:A4 portrait;margin:8mm 8mm 9mm}
    html,body{margin:0!important;padding:0!important;background:#fff!important;width:auto!important;min-width:0!important}
    body{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;font-size:9.2pt!important}
    .preview-toolbar{display:none!important}
    .sheet{display:block!important;width:auto!important;max-width:none!important;margin:0!important;overflow:visible!important;border-radius:0!important;box-shadow:none!important;background:#fff!important}
    .quotation-document-header{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;gap:5mm!important;padding:2.5mm 1mm 3mm!important;border-top-width:1.2mm!important;break-inside:avoid!important;page-break-inside:avoid!important}
    .quotation-brand-lockup{gap:4mm!important}
    .quotation-brand-lockup img{width:55mm!important;max-width:55mm!important;max-height:18mm!important}
    .quotation-brand-name{font-size:9pt!important}.quotation-brand-contact{font-size:7.2pt!important}.quotation-brand-address{font-size:6.8pt!important;max-width:95mm!important}
    .quotation-ref-block small{font-size:6.3pt!important}.quotation-ref-block strong{font-size:8.6pt!important}.quotation-ref-block span{font-size:6.5pt!important}
    .quote-hero{break-inside:avoid!important;page-break-inside:avoid!important}
    .quote-hero-copy{padding:5mm 6mm 4mm!important}
    .quote-hero-kicker{font-size:6.5pt!important;padding:1.2mm 2.2mm!important}
    .quote-hero h1{font-size:19pt!important;line-height:1.15!important;margin:2.2mm 0 1.5mm!important}
    .quote-hero-desc{font-size:8.4pt!important;line-height:1.48!important}
    .quote-feature-image img{height:66mm!important;object-fit:cover!important}
    .quote-feature-caption{left:4mm!important;bottom:3.5mm!important;font-size:6.6pt!important;padding:1.4mm 2.4mm!important}
    .content{padding:5mm 1mm 0!important}
    .customer-reference-panel{grid-template-columns:minmax(0,1.45fr) minmax(48mm,.55fr)!important;gap:3mm!important;margin-bottom:3mm!important;padding:3mm!important;border-radius:2.5mm!important;break-inside:avoid!important;page-break-inside:avoid!important}
    .customer-avatar{width:9mm!important;height:9mm!important;font-size:9pt!important}
    .customer-reference-main{gap:2.5mm!important}.customer-reference-main strong{font-size:11pt!important}.customer-contact-line{font-size:7.2pt!important}
    .customer-reference-meta{padding-left:3mm!important}.customer-reference-meta strong{font-size:9.2pt!important}.customer-reference-meta span{font-size:6.7pt!important}
    .quote-summary-grid{grid-template-columns:repeat(3,1fr)!important;gap:2mm!important;margin-bottom:4mm!important;break-inside:avoid!important}
    .quote-summary-card{padding:2.3mm 2.7mm!important;border-radius:2mm!important}.quote-summary-card small{font-size:6.3pt!important}.quote-summary-card strong{font-size:8.5pt!important}.quote-summary-card.price-card strong{font-size:12.5pt!important}
    .overview-prose{font-size:8.7pt!important;line-height:1.55!important}
    .journey-gallery{grid-template-columns:repeat(4,1fr)!important;gap:1.8mm!important;break-inside:avoid!important;page-break-inside:avoid!important}
    .journey-gallery figure{border-radius:1.5mm!important}.journey-gallery img{height:29mm!important}.journey-gallery figcaption{font-size:6.2pt!important;padding:1.2mm 1.4mm!important}
    .quotation-credentials{margin-top:4mm!important;padding:2.5mm 3mm!important;border-radius:2mm!important}
    .quotation-credentials-title{margin-bottom:2mm!important;font-size:6.5pt!important}.quotation-credentials-grid{display:block!important}.quotation-credential{width:28mm!important;min-width:0!important;max-width:28mm!important;margin:1mm!important;padding:1.3mm!important;border-radius:1.5mm!important}.quotation-credential img{width:auto!important;height:auto!important;max-width:12mm!important;max-height:7mm!important;margin-bottom:1mm!important}.quotation-credential span{font-size:6pt!important}
    .quotation-company-footer{margin-top:4mm!important;padding:3mm 4mm!important;border-radius:2mm!important}.quotation-company-footer strong{font-size:9.5pt!important}.quotation-company-footer .footer-address,.quotation-company-footer .footer-contact{font-size:6.6pt!important}.quotation-company-footer .footer-ref{font-size:6.2pt!important}
    .section,.day,.pricing,.document-list,.prose{max-width:100%!important}
    .section-head,.pricing thead,.customer-reference-panel,.quote-summary-grid,.journey-gallery,.quotation-credentials,.quotation-company-footer{break-inside:avoid!important;page-break-inside:avoid!important}
    .pricing{table-layout:fixed!important}.pricing th,.pricing td{overflow-wrap:anywhere!important}
    p,.prose,.day p{orphans:3!important;widows:3!important}
}
@media(max-width:700px){
    .quotation-document-header{align-items:flex-start;flex-direction:column}.quotation-ref-block{text-align:left}
    .quotation-brand-lockup{align-items:flex-start;flex-direction:column}.quotation-brand-lockup img{width:min(260px,85vw);max-width:100%}
    .quote-feature-image img{height:235px}.quote-hero-copy{padding:24px 20px 18px}.quote-hero h1{font-size:27px}
    .customer-reference-panel{grid-template-columns:1fr}.customer-reference-meta{padding:10px 0 0;border-left:0;border-top:1px solid #d6e6f5}
    .quote-summary-grid{grid-template-columns:1fr}.journey-gallery{grid-template-columns:repeat(2,1fr)}
}

</style>
<link rel="stylesheet" href="assets/crm-laptop-workspaces-v2498.css?v=2498">
</head>
<body>

<?php if ($includeToolbar): ?>
<div class="preview-toolbar">
    <a href="<?= e(BASE_URL . 'admin-crm.php?lead=' . $leadId . '#packageStudio'); ?>">← Back to CRM</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</div>
<?php endif; ?>

<main class="sheet">

<div class="quotation-document-header">
    <div class="quotation-brand-lockup">
        <?php if($quotationLogoUrl !== ''): ?>
            <img src="<?= e($quotationLogoUrl); ?>" alt="<?= e($siteName); ?> document logo">
        <?php endif; ?>
        <div class="quotation-brand-copy">
            <div class="quotation-brand-name"><?= e($quotationHeaderName); ?></div>
            <?php if($quotationContactLine !== ''): ?><div class="quotation-brand-contact"><?= e($quotationContactLine); ?></div><?php endif; ?>
            <?php if(!empty($quotationBrand['address'])): ?><div class="quotation-brand-address"><?= e((string)$quotationBrand['address']); ?></div><?php endif; ?>
        </div>
    </div>
    <div class="quotation-ref-block">
        <small>Official Travel Quotation</small>
        <strong><?= e($quotationReference); ?></strong>
        <span>Version <?= (int)$qv['version_number']; ?> · Prepared <?= e($quotationPreparedDate); ?></span>
    </div>
</div>

<section class="quote-hero">
    <div class="quote-hero-copy">
        <div class="quote-hero-kicker">Tailored Journey Proposal · Version <?= (int)$qv['version_number']; ?></div>
        <h1><?= e((string)$qv['package_title']); ?></h1>
        <div class="quote-hero-desc">
            <?= e((string)($qs['short_description'] ?? 'A thoughtfully prepared travel proposal designed around your journey.')); ?>
        </div>
    </div>
    <?php if($quotationFeatureImage!==''): ?>
        <div class="quote-feature-image">
            <img src="<?= e($quotationFeatureImage); ?>" alt="<?= e((string)$qv['package_title']); ?> featured journey image">
            <div class="quote-feature-caption">Featured journey · <?= e((string)($qv['customer_destination'] ?: $qv['package_title'])); ?></div>
        </div>
    <?php endif; ?>
</section>

<div class="content">

    <div class="customer-reference-panel">
        <div class="customer-reference-main">
            <div class="customer-avatar"><?= e(strtoupper(substr(trim((string)$qv['customer_name']) ?: 'C',0,1))); ?></div>
            <div>
                <small>Quotation Prepared For</small>
                <strong><?= e((string)$qv['customer_name']); ?></strong>
                <div class="customer-contact-line">
                    <?= e((string)($qv['customer_mobile'] ?: 'Mobile not provided')); ?>
                    &nbsp; · &nbsp;
                    <?= e((string)($qv['customer_email'] ?: 'Email not provided')); ?>
                </div>
            </div>
        </div>
        <div class="customer-reference-meta">
            <small>Quotation Reference</small>
            <strong><?= e($quotationReference); ?></strong>
            <span>Version <?= (int)$qv['version_number']; ?> · Keep this reference for amendments, confirmation and correspondence.</span>
        </div>
    </div>

    <div class="quote-summary-grid">
        <div class="quote-summary-card">
            <small>Travel Date</small>
            <strong><?= e(crmDate($qv['travel_date'])); ?></strong>
        </div>
        <div class="quote-summary-card">
            <small>Travellers</small>
            <strong><?= (int)$qv['adults']; ?> Adult(s) + <?= (int)$qv['children']; ?> Child(ren)</strong>
        </div>
        <div class="quote-summary-card price-card">
            <small>Quoted Package Value</small>
            <strong><?= e(crmMoney($qv['final_price'], $currencySymbol)); ?></strong>
        </div>
    </div>

    <?php if(!empty($qs['description'])): ?>
        <section class="section">
            <div class="section-head">
                <div class="section-icon">01</div>
                <h2>Package Overview</h2>
            </div>
            <div class="prose overview-prose"><?= nl2br(e((string)$qs['description'])); ?></div>
        </section>
    <?php endif; ?>

    <?php if($quotationGallery): ?>
        <section class="section">
            <div class="section-head">
                <div class="section-icon">G</div>
                <h2>Journey Gallery</h2>
            </div>
            <div class="section-intro">A visual preview of the destinations and experiences included across the itinerary.</div>
            <div class="journey-gallery">
                <?php foreach($quotationGallery as $galleryIndex => $galleryImage): ?>
                    <figure>
                        <img src="<?= e($galleryImage); ?>" alt="Journey gallery image <?= (int)$galleryIndex + 1; ?>">
                        <figcaption>Journey moment <?= (int)$galleryIndex + 1; ?></figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if($qPrices): ?>
        <section class="section">
            <div class="section-head">
                <div class="section-icon">02</div>
                <h2>Date-wise Hotel Pricing</h2>
            </div>

            <table class="pricing">
                <thead>
                    <tr>
                        <th>Valid From</th>
                        <th>Valid To</th>
                        <th>3 Star</th>
                        <th>4 Star</th>
                        <th>5 Star</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($qPrices as $r): ?>
                        <tr>
                            <td><?= e(crmDate($r['valid_from']??'')); ?></td>
                            <td><?= e(crmDate($r['valid_to']??'')); ?></td>
                            <td><?= e(crmMoney($r['price_3_star']??0,$currencySymbol)); ?></td>
                            <td><?= e(crmMoney($r['price_4_star']??0,$currencySymbol)); ?></td>
                            <td><?= e(crmMoney($r['price_5_star']??0,$currencySymbol)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <?php if($qHotels): ?>
        <section class="section">
            <div class="section-head">
                <div class="section-icon">03</div>
                <h2>Proposed Accommodation</h2>
            </div>
            <table class="pricing">
                <thead><tr><th>Destination</th><th>Nights</th><th>Category</th><th>Hotel / Alternatives</th><th>Room / Meal</th></tr></thead>
                <tbody>
                <?php foreach($qHotels as $hotelRow): if(!is_array($hotelRow)) continue;
                    $hotelNames=[];
                    $actual=trim((string)($hotelRow['hotel_name']??''));
                    if($actual!==''){$hotelNames[]=$actual;}
                    else {foreach((array)($hotelRow['alternatives']??[]) as $hn){$hn=trim((string)$hn);if($hn!=='')$hotelNames[]=$hn;}}
                    $displayHotels=$hotelNames?implode(' / ',array_slice($hotelNames,0,5)).($actual===''?' or similar':''):(($hotelRow['accommodation_type']??'')==='houseboat'?'Houseboat as per selected category':'To be confirmed');
                ?>
                    <tr>
                        <td><?= e((string)($hotelRow['destination_name']??'')); ?></td>
                        <td><?= max(1,(int)($hotelRow['nights']??1)); ?></td>
                        <td><?= !empty($hotelRow['hotel_category']) ? (int)$hotelRow['hotel_category'].' Star' : e(ucwords((string)($hotelRow['accommodation_type']??'Hotel'))); ?></td>
                        <td><?= e($displayHotels); ?></td>
                        <td><?= e(trim((string)($hotelRow['room_type']??'')) . ((trim((string)($hotelRow['meal_plan']??''))!=='') ? ' / '.trim((string)$hotelRow['meal_plan']) : '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="prose" style="margin-top:8px;color:#64748b;font-size:11px">Hotels shown as alternatives are subject to availability. A same/similar category property may be confirmed unless a specific hotel is explicitly marked confirmed.</div>
        </section>
    <?php endif; ?>

    <?php if($qTransport): ?>
        <section class="section">
            <div class="section-head">
                <div class="section-icon"><?= $qHotels ? '04' : '03'; ?></div>
                <h2>Proposed Transport</h2>
            </div>
            <table class="pricing">
                <thead><tr><th>Route / Service</th><th>Location</th><th>Vehicle / Alternatives</th><th>A/C Basis</th><th>Travellers</th></tr></thead>
                <tbody>
                <?php foreach($qTransport as $transportRow): if(!is_array($transportRow)) continue;
                    $vehicle=trim((string)($transportRow['vehicle_type']??''));
                    $vehicleAlternatives=[];
                    foreach((array)($transportRow['alternatives']??[]) as $vn){$vn=trim((string)$vn);if($vn!==''&&!in_array($vn,$vehicleAlternatives,true))$vehicleAlternatives[]=$vn;}
                    $vehicleDisplay=$vehicle!==''?$vehicle:($vehicleAlternatives?implode(' / ',array_slice($vehicleAlternatives,0,5)).' or similar':'As per itinerary / pax');
                ?>
                    <tr>
                        <td><?= e((string)($transportRow['route_label']??'Transport as per itinerary')); ?></td>
                        <td><?= e((string)($transportRow['destination_name']??'')); ?></td>
                        <td><?= e($vehicleDisplay); ?></td>
                        <td><?= e(((string)($transportRow['ac_basis']??'unspecified'))==='unspecified'?'As per supplier':strtoupper((string)$transportRow['ac_basis'])); ?></td>
                        <td><?= max(1,(int)($transportRow['pax_total']??1)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="prose" style="margin-top:8px;color:#64748b;font-size:11px">Vehicle type shown before supplier confirmation is proposed and subject to availability / passenger count. Final voucher uses the confirmed vehicle details.</div>
        </section>
    <?php endif; ?>

    <?php if($qFlights): ?>
        <section class="section">
            <div class="section-head"><div class="section-icon">F</div><h2>Suggested Flight Options</h2></div>
            <table class="pricing"><thead><tr><th>Sector</th><th>Airline / Flight</th><th>Departure</th><th>Arrival</th><th>Stops / Cabin</th><th>Fare*</th></tr></thead><tbody>
            <?php foreach($qFlights as $f): if(!is_array($f)) continue; ?>
                <tr><td><?= e((string)($f['origin_code']??'')); ?> → <?= e((string)($f['destination_code']??'')); ?></td><td><?= e(trim((string)($f['airline_name']??'').' '.(string)($f['flight_number']??''))); ?></td><td><?= !empty($f['departure_at'])?e(date('d M Y H:i',strtotime((string)$f['departure_at']))):'-'; ?></td><td><?= !empty($f['arrival_at'])?e(date('d M Y H:i',strtotime((string)$f['arrival_at']))):'-'; ?></td><td><?= (int)($f['stops']??0)===0?'Direct':(int)$f['stops'].' Stop(s)'; ?><?= !empty($f['cabin_class'])?' · '.e(ucwords(strtolower(str_replace('_',' ',(string)$f['cabin_class'])))):''; ?></td><td><?= !empty($f['display_fare']) && (float)($f['fare_amount']??0)>0 ? e((string)($f['currency']??'INR')).' '.number_format((float)$f['fare_amount'],2) : 'Hidden'; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table><div class="prose" style="margin-top:8px;color:#64748b;font-size:11px">Flight details are suggested for itinerary planning only. Schedules, availability, fares and baggage allowances may change until booking is confirmed and ticketed.</div>
        </section>
    <?php endif; ?>

    <?php if($qTrains): ?>
        <section class="section">
            <div class="section-head"><div class="section-icon">T</div><h2>Suggested Train Options</h2></div>
            <table class="pricing"><thead><tr><th>Sector</th><th>Train</th><th>Departure</th><th>Arrival</th><th>Class</th><th>Fare*</th></tr></thead><tbody>
            <?php foreach($qTrains as $r): if(!is_array($r)) continue; ?>
                <tr><td><?= e((string)($r['origin_code']??'')); ?> → <?= e((string)($r['destination_code']??'')); ?></td><td><?= e(trim((string)($r['train_name']??'').' '.(string)($r['train_number']??''))); ?></td><td><?= !empty($r['departure_at'])?e(date('d M Y H:i',strtotime((string)$r['departure_at']))):'-'; ?></td><td><?= !empty($r['arrival_at'])?e(date('d M Y H:i',strtotime((string)$r['arrival_at']))):'-'; ?></td><td><?= e((string)($r['travel_class']??'-')); ?></td><td><?= !empty($r['display_fare']) && (float)($r['fare_amount']??0)>0 ? e((string)($r['currency']??'INR')).' '.number_format((float)$r['fare_amount'],2) : 'Hidden'; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table><div class="prose" style="margin-top:8px;color:#64748b;font-size:11px">Train details are suggested for itinerary planning only. Schedule, availability, fare and class availability may change until the ticket is confirmed.</div>
        </section>
    <?php endif; ?>

    <?php if($qItinerary): ?>
        <section class="section">
            <div class="section-head">
                <div class="section-icon"><?= sprintf('%02d', 3 + $qServiceSectionCount); ?></div>
                <h2>Day-wise Itinerary</h2>
            </div>

            <?php foreach($qItinerary as $d):
                $dayTitleText=trim((string)($d['title']??''));
                $dayDescriptionText=trim((string)($d['description']??''));
                $dayImageText=trim((string)($d['image']??''));
                if($dayTitleText==='' && $dayDescriptionText==='' && $dayImageText==='') continue;
                $di=crmImageUrl($dayImageText);
            ?>
                <?php
                $quotationDayNumber=max(1,(int)($d['day_number']??0));
                $quotationDayNarrative=$dayDescriptionText;
                $quotationDayRoute='';
                if($quotationDayNarrative!=='' && preg_match('/\s*(\[(?:Start|Via\/Sightseeing|Via|End|Distance|Approx(?:imate)?\s+travel\s+time)[^\]]*\])\s*$/isu',$quotationDayNarrative,$quotationRouteMatch)){
                    $quotationDayRoute=trim((string)($quotationRouteMatch[1]??''));
                    $quotationDayNarrative=trim(substr($quotationDayNarrative,0,-strlen((string)$quotationRouteMatch[0])));
                }
                ?>
                <div class="day">
                    <div class="day-heading">
                        <div class="day-badge"><span>DAY<br><?= $quotationDayNumber; ?></span></div>
                        <div class="day-title-wrap">
                            <span class="day-kicker">Day <?= $quotationDayNumber; ?> · Your Journey</span>
                            <span class="day-title"><?= e($dayTitleText!==''?$dayTitleText:'Day '.$quotationDayNumber); ?></span>
                        </div>
                    </div>

                    <div class="day-flow">
                        <?php if($di!==''): ?>
                            <div class="day-image-wrap">
                                <img src="<?= e($di); ?>" alt="<?= e($dayTitleText!==''?$dayTitleText:'Day '.$quotationDayNumber); ?>">
                            </div>
                        <?php endif; ?>

                        <div class="day-content">
                            <?php if($quotationDayNarrative!==''): ?><p><?= nl2br(e($quotationDayNarrative)); ?></p><?php endif; ?>
                        </div>
                    </div>

                    <?php if($quotationDayRoute!==''): ?><div class="day-route"><?= e($quotationDayRoute); ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php
    $styledSections = [
        'tour_highlights' => ['Highlights','highlight-section',sprintf('%02d', 4 + $qServiceSectionCount),'Signature experiences selected for this journey.'],
        'inclusions'      => ['Inclusions','inclusion-section',sprintf('%02d', 5 + $qServiceSectionCount),'Services covered within the quoted package price.'],
        'exclusions'      => ['Exclusions','exclusion-section',sprintf('%02d', 6 + $qServiceSectionCount),'Items payable separately unless specifically confirmed in writing.'],
        'terms'           => ['Terms & Conditions','terms-section',sprintf('%02d', 7 + $qServiceSectionCount),'Important commercial and operational conditions for this quotation.'],
        'customer_notes'  => ['Customer Notes','notes-section',sprintf('%02d', 8 + $qServiceSectionCount),'Additional notes prepared for this journey.'],
    ];

    foreach($styledSections as $key=>$cfg):
        if(empty($qs[$key])) continue;
        $sectionValue=(string)$qs[$key];
        $sectionLines=array_values(array_filter(array_map('trim',preg_split('/\R+/', $sectionValue) ?: []),static fn($line)=>$line!==''));
        $renderAsCards=in_array($key,['tour_highlights','inclusions','exclusions'],true);
    ?>
        <section class="section <?= e($cfg[1]); ?>">
            <div class="section-head">
                <div class="section-icon"><?= e($cfg[2]); ?></div>
                <h2><?= e($cfg[0]); ?></h2>
            </div>
            <div class="section-intro"><?= e($cfg[3]); ?></div>

            <?php if($renderAsCards): ?>
                <div class="document-list">
                    <?php foreach($sectionLines as $sectionLine):
                        $cleanSectionLine=preg_replace('/^[\x{2022}\x{2023}\x{25CF}\x{2713}\x{2714}\-\*]+\s*/u','',$sectionLine) ?? $sectionLine;
                        $listMark=$key==='exclusions'?'−':($key==='inclusions'?'✓':'•');
                    ?>
                        <div class="document-list-item">
                            <span class="document-list-mark"><?= e($listMark); ?></span>
                            <span><?= e($cleanSectionLine); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="prose"><?= nl2br(e($sectionValue)); ?></div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if($quotationCredentials): ?>
        <section class="quotation-credentials">
            <div class="quotation-credentials-title"><?= e((string)($quotationBrand['credentials_heading'] ?? 'Recognitions & Associations')); ?></div>
            <div class="quotation-credentials-grid">
                <?php foreach($quotationCredentials as $credential): ?>
                    <div class="quotation-credential">
                        <img src="<?= e((string)$credential['logo']); ?>" alt="<?= e((string)$credential['label']); ?>">
                        <span><?= e((string)$credential['label']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="quotation-company-footer">
        <strong><?= e($siteName); ?></strong>
        <?php if(!empty($quotationBrand['address'])): ?><div class="footer-address"><?= e((string)$quotationBrand['address']); ?></div><?php endif; ?>
        <?php if($quotationContactLine !== ''): ?><div class="footer-contact"><?= e($quotationContactLine); ?></div><?php endif; ?>
        <div class="footer-ref">Quotation Reference <?= e($quotationReference); ?> · Version <?= (int)$qv['version_number']; ?> · Thank you for the opportunity to plan your journey.</div>
    </div>

</div>
</main>
</body>
</html>

<?php
    return (string)ob_get_clean();
}


function crmQuotationCommandExists(string $command): bool
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $result =
        trim(
            (string)@shell_exec(
                'command -v '
                . escapeshellarg($command)
                . ' 2>/dev/null'
            )
        );

    return $result !== '';
}


function crmRenderZenzQuotationPdf(
    string $html,
    array $fallbackVersion,
    array $fallbackLead,
    array $fallbackSnapshot,
    string $siteName,
    string $currencySymbol
): string {

    /*
    |--------------------------------------------------------------------------
    | 1. DOMPDF
    |--------------------------------------------------------------------------
    */

    if (!class_exists('\\Dompdf\\Dompdf')) {

        foreach ([
            __DIR__ . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ] as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;

                if (class_exists('\\Dompdf\\Dompdf')) {
                    break;
                }
            }
        }
    }

    if (class_exists('\\Dompdf\\Dompdf')) {
        try {
            $options =
                new \Dompdf\Options();

            $options->set(
                'isRemoteEnabled',
                true
            );

            $options->set(
                'isHtml5ParserEnabled',
                true
            );

            $options->set(
                'defaultFont',
                'DejaVu Sans'
            );

            $dompdf =
                new \Dompdf\Dompdf(
                    $options
                );

            $dompdf->loadHtml(
                $html,
                'UTF-8'
            );

            $dompdf->setPaper(
                'A4',
                'portrait'
            );

            $dompdf->render();

            $bytes =
                (string)$dompdf->output();

            if (
                str_starts_with(
                    $bytes,
                    '%PDF'
                )
            ) {
                return $bytes;
            }

        } catch (Throwable $e) {
            error_log(
                'TRAVSCOPE CRM Dompdf quotation render error: '
                . $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 2. MPDF
    |--------------------------------------------------------------------------
    */

    if (class_exists('\\Mpdf\\Mpdf')) {
        try {
            $tempDir =
                sys_get_temp_dir()
                . '/travscope_mpdf';

            if (!is_dir($tempDir)) {
                @mkdir(
                    $tempDir,
                    0700,
                    true
                );
            }

            $mpdf =
                new \Mpdf\Mpdf([
                    'format' => 'A4',
                    'tempDir' => $tempDir,
                    'margin_left' => 8,
                    'margin_right' => 8,
                    'margin_top' => 8,
                    'margin_bottom' => 8,
                ]);

            $mpdf->WriteHTML($html);

            $bytes =
                (string)$mpdf->Output(
                    '',
                    \Mpdf\Output\Destination::STRING_RETURN
                );

            if (
                str_starts_with(
                    $bytes,
                    '%PDF'
                )
            ) {
                return $bytes;
            }

        } catch (Throwable $e) {
            error_log(
                'TRAVSCOPE CRM mPDF quotation render error: '
                . $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 3. WKHTMLTOPDF
    |--------------------------------------------------------------------------
    */

    if (
        function_exists('shell_exec')
        &&
        crmQuotationCommandExists(
            'wkhtmltopdf'
        )
    ) {
        $htmlFile =
            tempnam(
                sys_get_temp_dir(),
                'travscope_quotation_html_'
            );

        $pdfFile =
            tempnam(
                sys_get_temp_dir(),
                'travscope_quotation_pdf_'
            );

        if (
            $htmlFile !== false
            &&
            $pdfFile !== false
        ) {
            try {
                @file_put_contents(
                    $htmlFile,
                    $html
                );

                $command =
                    'wkhtmltopdf '
                    . '--quiet '
                    . '--enable-local-file-access '
                    . '--print-media-type '
                    . '--page-size A4 '
                    . '--margin-top 8mm '
                    . '--margin-right 8mm '
                    . '--margin-bottom 8mm '
                    . '--margin-left 8mm '
                    . escapeshellarg(
                        $htmlFile
                    )
                    . ' '
                    . escapeshellarg(
                        $pdfFile
                    );

                @shell_exec(
                    $command
                    . ' 2>/dev/null'
                );

                if (
                    is_file($pdfFile)
                    &&
                    filesize($pdfFile) > 500
                ) {
                    $bytes =
                        (string)@file_get_contents(
                            $pdfFile
                        );

                    if (
                        str_starts_with(
                            $bytes,
                            '%PDF'
                        )
                    ) {
                        return $bytes;
                    }
                }

            } finally {
                @unlink($htmlFile);
                @unlink($pdfFile);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 4. HEADLESS CHROME / CHROMIUM
    |--------------------------------------------------------------------------
    */

    $chromeCommand = '';

    foreach ([
        'chromium',
        'chromium-browser',
        'google-chrome',
        'google-chrome-stable',
    ] as $candidate) {
        if (
            crmQuotationCommandExists(
                $candidate
            )
        ) {
            $chromeCommand = $candidate;
            break;
        }
    }

    if (
        $chromeCommand !== ''
        &&
        function_exists('shell_exec')
    ) {
        $htmlFile =
            tempnam(
                sys_get_temp_dir(),
                'travscope_chrome_html_'
            );

        $pdfFile =
            tempnam(
                sys_get_temp_dir(),
                'travscope_chrome_pdf_'
            );

        if (
            $htmlFile !== false
            &&
            $pdfFile !== false
        ) {
            try {
                @file_put_contents(
                    $htmlFile,
                    $html
                );

                $fileUrl =
                    'file://'
                    . $htmlFile;

                $command =
                    $chromeCommand
                    . ' --headless '
                    . '--no-sandbox '
                    . '--disable-gpu '
                    . '--disable-dev-shm-usage '
                    . '--no-pdf-header-footer '
                    . '--print-to-pdf='
                    . escapeshellarg(
                        $pdfFile
                    )
                    . ' '
                    . escapeshellarg(
                        $fileUrl
                    );

                @shell_exec(
                    $command
                    . ' 2>/dev/null'
                );

                if (
                    is_file($pdfFile)
                    &&
                    filesize($pdfFile) > 500
                ) {
                    $bytes =
                        (string)@file_get_contents(
                            $pdfFile
                        );

                    if (
                        str_starts_with(
                            $bytes,
                            '%PDF'
                        )
                    ) {
                        return $bytes;
                    }
                }

            } finally {
                @unlink($htmlFile);
                @unlink($pdfFile);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SAFE FALLBACK
    |--------------------------------------------------------------------------
    |
    | If the hosting server has no HTML-to-PDF engine, keep quotation delivery
    | working with the existing built-in PDF generator rather than failing.
    |
    */

    error_log(
        'TRAVSCOPE CRM: exact HTML-to-PDF renderer unavailable; using built-in quotation PDF fallback.'
    );

    return crmQuotationPdfBytes(
        $fallbackVersion,
        $fallbackLead,
        $fallbackSnapshot,
        $siteName,
        $currencySymbol
    );
}




/*
|--------------------------------------------------------------------------
| AJAX: LOAD / SYNC ONE QUOTATION VERSION CONVERSATION
|--------------------------------------------------------------------------
*/

if (!empty($_GET['quotation_conversation'])) {

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    $leadId =
        max(
            0,
            (int)($_GET['lead'] ?? 0)
        );

    $versionId =
        max(
            0,
            (int)($_GET['version'] ?? 0)
        );

    try {
        $stmt =
            $pdo->prepare("
                SELECT
                    v.id,
                    v.lead_id,
                    v.version_number,
                    v.package_title,
                    v.email_sent_at,
                    e.name AS customer_name,
                    e.email AS customer_email,
                    e.mobile AS customer_mobile
                FROM crm_package_versions v
                INNER JOIN enquiries e
                    ON e.id = v.lead_id
                WHERE v.id = ?
                  AND v.lead_id = ?
                LIMIT 1
            ");

        $stmt->execute([
            $versionId,
            $leadId
        ]);

        $version =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$version) {
            throw new RuntimeException(
                'Quotation version not found.'
            );
        }

        $sync =
            crmSyncQuotationEmailReplies(
                $pdo,
                $leadId,
                $versionId,
                (string)($version['customer_email'] ?? ''),
                (string)($version['package_title'] ?? ''),
                $version['email_sent_at'] ?? null
            );

        echo json_encode(
            [
                'ok' => true,
                'version' => [
                    'version_number' =>
                        (int)$version['version_number'],
                    'package_title' =>
                        (string)$version['package_title'],
                    'customer_name' =>
                        (string)$version['customer_name'],
                    'customer_email' =>
                        (string)($version['customer_email'] ?? ''),
                    'customer_mobile' =>
                        (string)($version['customer_mobile'] ?? ''),
                ],
                'sync' => $sync,
                'messages' =>
                    array_map(
                        static function (array $row): array {
                            return [
                                'direction' =>
                                    (string)$row['direction'],
                                'sender_email' =>
                                    (string)($row['sender_email'] ?? ''),
                                'recipient_email' =>
                                    (string)($row['recipient_email'] ?? ''),
                                'subject' =>
                                    (string)($row['subject'] ?? ''),
                                'body_text' =>
                                    (string)($row['body_text'] ?? ''),
                                'sent_at' =>
                                    (string)($row['sent_at'] ?? ''),
                            ];
                        },
                        crmQuotationMessages(
                            $pdo,
                            $leadId,
                            $versionId
                        )
                    ),
            ],
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

    } catch (Throwable $e) {
        http_response_code(400);

        echo json_encode(
            [
                'ok' => false,
                'message' => $e->getMessage(),
            ],
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );
    }

    exit;
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = crmPost('csrf_token');
    $action = crmPost('action');

    if ($token === '' || !hash_equals($csrfToken, $token)) {
        $error = 'Security verification failed. Refresh the page and try again.';
    } elseif (!$crmReady) {
        $error = 'Import travscope-professional-crm-upgrade.sql before using this CRM.';
    } else {
        try {
            /* V10: Hotel + Vehicle controls directly inside saved quotation versions. */
            if (in_array($action, ['quotation_build_services','quotation_update_hotel','quotation_update_transport'], true)) {
                $leadId = max(0, (int)($_POST['lead_id'] ?? 0));
                $versionId = max(0, (int)($_POST['version_id'] ?? 0));
                if ($leadId <= 0 || $versionId <= 0) {
                    throw new RuntimeException('Invalid quotation hotel/vehicle update request.');
                }

                $stmt = $pdo->prepare("SELECT * FROM crm_package_versions WHERE id=? AND lead_id=? LIMIT 1");
                $stmt->execute([$versionId, $leadId]);
                $quotationVersion = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$quotationVersion) {
                    throw new RuntimeException('Quotation version was not found for this customer.');
                }

                $quotationTripForUpdate = function_exists('crmTripByEnquiry') ? crmTripByEnquiry($pdo, $leadId) : null;
                if (!$quotationTripForUpdate || (int)($quotationTripForUpdate['id'] ?? 0) <= 0) {
                    throw new RuntimeException('Create/open the CRM Trip first. Hotel and vehicle allocation belongs to the Trip quotation.');
                }
                $quotationTripId = (int)$quotationTripForUpdate['id'];

                if ($action === 'quotation_build_services') {
                    $snapshotForBuild = crmCustomerPackageSnapshot((string)($quotationVersion['custom_notes'] ?? ''));
                    $snapshotForBuild = crmHydrateQuotationSnapshot($pdo, $quotationVersion, $snapshotForBuild);
                    $hotelMsg = 'Hotel engine unavailable.';
                    $vehicleMsg = 'Transport engine unavailable.';

                    if (function_exists('tsHABuildForQuotation')) {
                        $built = tsHABuildForQuotation(
                            $pdo,
                            $quotationTripId,
                            $versionId,
                            (string)($quotationVersion['hotel_category'] ?? ($snapshotForBuild['pricing']['hotel_category'] ?? '4')),
                            !empty($quotationVersion['travel_date']) ? (string)$quotationVersion['travel_date'] : null,
                            $snapshotForBuild['itinerary_days'] ?? $quotationVersion['itinerary'] ?? [],
                            $adminId,
                            false
                        );
                        $hotelMsg = (int)($built['created'] ?? 0) > 0
                            ? (int)$built['created'] . ' hotel stay segment(s) created.'
                            : ((string)($built['warning'] ?? '') ?: 'Existing hotel plan kept.');
                    }
                    if (function_exists('tsTABuildForQuotation')) {
                        $built = tsTABuildForQuotation($pdo, $quotationTripId, $versionId, $adminId, false);
                        $vehicleMsg = (int)($built['created'] ?? 0) > 0
                            ? (int)$built['created'] . ' transport segment(s) created.'
                            : ((string)($built['warning'] ?? '') ?: 'Existing transport plan kept.');
                    }
                    $_SESSION['admin_crm_success'] = 'Quotation hotel/vehicle plan refreshed. ' . $hotelMsg . ' ' . $vehicleMsg;
                    crmRedirect(BASE_URL . 'admin-crm.php?lead=' . $leadId . '&quote_version=' . $versionId . '#quotationVersion-' . $versionId);
                }

                if ($action === 'quotation_update_hotel') {
                    if (!function_exists('tsHAAllocationById') || !function_exists('tsHAUpdateAllocation')) {
                        throw new RuntimeException('Destination Hotel Allocation engine is not available.');
                    }
                    $allocationId = max(0, (int)($_POST['allocation_id'] ?? 0));
                    $allocation = tsHAAllocationById($pdo, $allocationId);
                    if (!$allocation
                        || (int)($allocation['quotation_version_id'] ?? 0) !== $versionId
                        || (int)($allocation['trip_id'] ?? 0) !== $quotationTripId) {
                        throw new RuntimeException('This hotel row does not belong to the selected quotation.');
                    }
                    tsHAUpdateAllocation($pdo, $allocationId, [
                        'hotel_choice' => crmPost('hotel_choice', 'none'),
                        // Backward-compatible fields for older forms/API callers.
                        'inventory_id' => max(0, (int)($_POST['inventory_id'] ?? 0)),
                        'hotel_id' => max(0, (int)($_POST['hotel_id'] ?? 0)),
                        'allocation_status' => crmPost('allocation_status', (string)($allocation['allocation_status'] ?? 'proposed')),
                        'room_type' => crmPost('room_type'),
                        'meal_plan' => crmPost('meal_plan'),
                        'supplier_confirmation_ref' => crmPost('supplier_confirmation_ref'),
                        'supplier_note' => crmPost('supplier_note'),
                    ], $adminId);
                    $_SESSION['admin_crm_success'] = 'Quotation hotel updated from Hotel Master Pro / supplier location inventory.';
                    crmRedirect(BASE_URL . 'admin-crm.php?lead=' . $leadId . '&quote_version=' . $versionId . '#quotationVersion-' . $versionId);
                }

                if ($action === 'quotation_update_transport') {
                    if (!function_exists('tsTAAllocationById') || !function_exists('tsTAUpdateAllocation')) {
                        throw new RuntimeException('Supplier Vehicle / Transport Allocation engine is not available.');
                    }
                    $allocationId = max(0, (int)($_POST['allocation_id'] ?? 0));
                    $allocation = tsTAAllocationById($pdo, $allocationId);
                    if (!$allocation
                        || (int)($allocation['quotation_version_id'] ?? 0) !== $versionId
                        || (int)($allocation['trip_id'] ?? 0) !== $quotationTripId) {
                        throw new RuntimeException('This transport row does not belong to the selected quotation.');
                    }
                    tsTAUpdateAllocation($pdo, $allocationId, [
                        'vehicle_rule_id' => max(0, (int)($_POST['vehicle_rule_id'] ?? 0)),
                        'allocation_status' => crmPost('allocation_status', (string)($allocation['allocation_status'] ?? 'proposed')),
                        'pickup_point' => crmPost('pickup_point'),
                        'pickup_time' => crmPost('pickup_time'),
                        'drop_point' => crmPost('drop_point'),
                        'driver_name' => crmPost('driver_name'),
                        'driver_mobile' => crmPost('driver_mobile'),
                        'vehicle_number' => crmPost('vehicle_number'),
                        'supplier_confirmation_ref' => crmPost('supplier_confirmation_ref'),
                        'supplier_note' => crmPost('supplier_note'),
                    ], $adminId);
                    $_SESSION['admin_crm_success'] = 'Quotation vehicle/transport updated from the selected supplier city-wise inventory.';
                    crmRedirect(BASE_URL . 'admin-crm.php?lead=' . $leadId . '&quote_version=' . $versionId . '#quotationVersion-' . $versionId);
                }
            }

            if ($action === 'save_trip') {
                $leadId = (int)($_POST['lead_id'] ?? 0);
                $tripId = (int)($_POST['trip_id'] ?? 0);
                if ($leadId <= 0 || $tripId <= 0 || !crmTripsReady($pdo)) throw new RuntimeException('Invalid Trip.');
                $trip = crmTripById($pdo, $tripId);
                if (!$trip || (int)$trip['enquiry_id'] !== $leadId) throw new RuntimeException('Trip does not belong to this enquiry.');
                $stage = crmPost('trip_stage');
                if (!in_array($stage, crmTripStages(), true)) throw new RuntimeException('Invalid Trip stage.');
                $owner = max(0, (int)($_POST['trip_owner_admin_id'] ?? 0));
                $probability = max(0, min(100, (int)($_POST['trip_probability'] ?? 50)));
                $expected = max(0, (float)($_POST['trip_expected_value'] ?? 0));
                $nextAction = trim((string)($_POST['trip_next_action'] ?? ''));
                $nextDate = trim((string)($_POST['trip_next_action_date'] ?? ''));
                $nextDate = $nextDate !== '' ? $nextDate : null;
                $tripStatus = in_array($stage, ['won'], true) ? 'won' : (in_array($stage, ['lost'], true) ? 'lost' : 'active');
                $stmt = $pdo->prepare('UPDATE trips SET owner_admin_id=?, stage=?, status=?, expected_value=?, probability=?, next_action=?, next_action_date=?, updated_at=NOW() WHERE id=? AND enquiry_id=?');
                $stmt->execute([$owner > 0 ? $owner : null,$stage,$tripStatus,$expected,$probability,$nextAction !== '' ? $nextAction : null,$nextDate,$tripId,$leadId]);
                $_SESSION['admin_crm_success'] = 'Trip workspace updated successfully.';
                crmRedirect(BASE_URL . 'admin-crm.php?lead=' . $leadId . '&trip=' . $tripId);
            }

            if ($action === 'save_service_requirement') {
                $leadId=max(0,(int)($_POST['lead_id']??0)); $tripId=max(0,(int)($_POST['trip_id']??0));
                if(!crmServiceRequirementsReady($pdo)) throw new RuntimeException('Service Requirement SQL is not installed yet.');
                $trip=crmTripById($pdo,$tripId); if(!$trip || (int)$trip['enquiry_id']!==$leadId) throw new RuntimeException('Invalid Trip / Enquiry relationship.');
                $type=strtolower(crmPost('service_type','other')); if(!in_array($type,crmServiceRequirementTypes(),true)) $type='other';
                $status=strtolower(crmPost('service_status','rate_required')); if(!in_array($status,crmServiceRequirementStatuses(),true)) $status='rate_required';
                $title=crmPost('service_title'); if($title==='') $title=crmServiceRequirementTypeLabel($type);
                $destinationId=max(0,(int)($_POST['service_destination_id']??0)); $from=crmPost('service_date_from'); $to=crmPost('service_date_to');
                if($from!=='' && $to!=='' && $to<$from) throw new RuntimeException('Service end date cannot be before the start date.');
                $qty=max(0.01,(float)($_POST['service_quantity']??1)); $spec=crmPost('service_specifications');
                $st=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM trip_service_requirements WHERE trip_id=?'); $st->execute([$tripId]); $sort=(int)$st->fetchColumn();
                $st=$pdo->prepare("INSERT INTO trip_service_requirements(trip_id,enquiry_id,service_type,title,destination_id,service_date_from,service_date_to,quantity,adults,children,specifications,status,sort_order,created_by_admin_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$tripId,$leadId,$type,$title,$destinationId?:null,$from?:null,$to?:null,$qty,max(0,(int)($trip['adults']??0)),max(0,(int)($trip['children']??0)),$spec?:null,$status,$sort,$adminId?:null]);
                $_SESSION['admin_crm_success']='Service requirement added to the Trip.';
                crmRedirect(BASE_URL.'admin-crm.php?lead='.$leadId.'&trip='.$tripId.'#crm-costing');
            }
            if ($action === 'delete_service_requirement') {
                $leadId=max(0,(int)($_POST['lead_id']??0)); $tripId=max(0,(int)($_POST['trip_id']??0)); $rid=max(0,(int)($_POST['service_requirement_id']??0));
                if($rid<=0) throw new RuntimeException('Invalid service requirement.');
                $pdo->beginTransaction();
                if(crmCostSheetReady($pdo)){ $st=$pdo->prepare('UPDATE cost_sheet_items SET service_requirement_id=NULL WHERE service_requirement_id=?'); $st->execute([$rid]); }
                $st=$pdo->prepare('DELETE FROM trip_service_requirements WHERE id=? AND trip_id=? AND enquiry_id=?'); $st->execute([$rid,$tripId,$leadId]); $pdo->commit();
                $_SESSION['admin_crm_success']='Service requirement removed.'; crmRedirect(BASE_URL.'admin-crm.php?lead='.$leadId.'&trip='.$tripId.'#crm-costing');
            }
            if ($action === 'sync_cost_sheet') {
                $leadId=max(0,(int)($_POST['lead_id']??0)); $tripId=max(0,(int)($_POST['trip_id']??0));
                if(!crmCostSheetReady($pdo)) throw new RuntimeException('Cost Sheet SQL is not installed yet.');
                $trip=crmTripById($pdo,$tripId); if(!$trip || (int)$trip['enquiry_id']!==$leadId) throw new RuntimeException('Invalid Trip / Enquiry relationship.');
                $approved=crmSelectedSupplierCosting($pdo,$leadId); if(empty($approved['rows'])) throw new RuntimeException('No selected supplier quotation is available yet. Select the approved supplier quote first.');
                if(empty($approved['valid'])) throw new RuntimeException((string)($approved['error']??'Selected supplier costing is not valid.'));
                $sheet=crmEnsureActiveCostSheet($pdo,$tripId,$leadId,$adminId,(string)($approved['currency']??'INR')); $sheetId=(int)($sheet['id']??0); if($sheetId<=0) throw new RuntimeException('Unable to prepare the Trip Cost Sheet.');
                $reqs=crmTripServiceRequirements($pdo,$tripId); $byType=[]; foreach($reqs as $r){$k=strtolower((string)$r['service_type']); if($k!==''&&!isset($byType[$k]))$byType[$k]=(int)$r['id'];}
                $pdo->beginTransaction(); $pdo->prepare("DELETE FROM cost_sheet_items WHERE cost_sheet_id=? AND source_type='supplier_quote'")->execute([$sheetId]);
                $ins=$pdo->prepare("INSERT INTO cost_sheet_items(cost_sheet_id,service_requirement_id,supplier_id,supplier_request_id,supplier_quote_id,supplier_quote_item_id,source_type,item_type,description,quantity,unit_cost,line_cost,sort_order,created_by_admin_id) VALUES(?,?,?,?,?,?,'supplier_quote',?,?,?,?,?,?,?)");
                $sort=10;
                foreach((array)$approved['rows'] as $q){
                    $sid=max(0,(int)($q['supplier_id']??0)); $rq=max(0,(int)($q['request_id']??0)); $qid=max(0,(int)($q['quote_id']??0)); $sname=trim((string)($q['company_name']??'Supplier')); $rcode=trim((string)($q['request_code']??''));

                    /*
                     * One authoritative supplier source per exact Trip service.
                     * A newly selected Supplier Quote replaces an older Contract Rate
                     * for that same service so the Cost Sheet cannot double count it.
                     */
                    $linkedServiceForQuote=max(0,(int)($q['service_requirement_id']??0));

                    if($linkedServiceForQuote>0){
                        if(crmTableExists($pdo,'cost_sheet_rate_sources')){
                            $cleanup=$pdo->prepare("
                                DELETE FROM cost_sheet_rate_sources
                                WHERE cost_sheet_item_id IN (
                                    SELECT id
                                    FROM cost_sheet_items
                                    WHERE cost_sheet_id=?
                                      AND service_requirement_id=?
                                      AND source_type='contract_rate'
                                )
                            ");
                            $cleanup->execute([$sheetId,$linkedServiceForQuote]);
                        }

                        $cleanup=$pdo->prepare("
                            DELETE FROM cost_sheet_items
                            WHERE cost_sheet_id=?
                              AND service_requirement_id=?
                              AND source_type='contract_rate'
                        ");
                        $cleanup->execute([$sheetId,$linkedServiceForQuote]);
                    }

                    foreach((array)($q['items']??[]) as $item){
                        $itype=strtolower(trim((string)($item['item_type']??'other'))); $desc=trim((string)($item['description']??'')) ?: crmServiceRequirementTypeLabel($itype);
                        $qty=max(0.01,(float)($item['quantity']??1)); $unit=max(0,(float)($item['unit_cost']??0)); $line=max(0,(float)($item['line_cost']??($unit*$qty))); if($line<=0)continue;
                        $linkedRid=max(0,(int)($q['service_requirement_id']??0));
                        $match=$itype==='upgrade'?'other':$itype; if($match==='package')$match='';
                        $rid=$linkedRid>0?$linkedRid:($match!==''&&isset($byType[$match])?$byType[$match]:null);
                        $ins->execute([$sheetId,$rid,$sid?:null,$rq?:null,$qid?:null,((int)($item['id']??0))?:null,$itype?:'other',$sname.($rcode!==''?' · '.$rcode:'').' — '.$desc,$qty,$unit?:$line,$line,$sort,$adminId?:null]); $sort+=10;
                    }
                    $linkedRid=max(0,(int)($q['service_requirement_id']??0))?:null;
                    $tax=max(0,(float)($q['tax_amount']??0)); if($tax>0){$ins->execute([$sheetId,$linkedRid,$sid?:null,$rq?:null,$qid?:null,null,'supplier_tax',$sname.($rcode!==''?' · '.$rcode:'').' — Supplier GST / Tax',1,$tax,$tax,$sort,$adminId?:null]);$sort+=10;}
                    $other=max(0,(float)($q['upgrade_amount']??0)); if($other>0){$ins->execute([$sheetId,$linkedRid,$sid?:null,$rq?:null,$qid?:null,null,'other',$sname.($rcode!==''?' · '.$rcode:'').' — Supplier Other / Upgrade Charges',1,$other,$other,$sort,$adminId?:null]);$sort+=10;}
                }
                $pdo->commit(); crmRecalculateCostSheet($pdo,$sheetId); $_SESSION['admin_crm_success']='Trip Cost Sheet refreshed from selected supplier quotation(s).';
                crmRedirect(BASE_URL.'admin-crm.php?lead='.$leadId.'&trip='.$tripId.'#crm-costing');
            }
            if ($action === 'add_manual_cost_item') {
                $leadId=max(0,(int)($_POST['lead_id']??0)); $tripId=max(0,(int)($_POST['trip_id']??0)); $trip=crmTripById($pdo,$tripId); if(!$trip||(int)$trip['enquiry_id']!==$leadId)throw new RuntimeException('Invalid Trip / Enquiry relationship.');
                $desc=crmPost('manual_cost_description'); $type=strtolower(crmPost('manual_cost_type','other')); if(!in_array($type,crmServiceRequirementTypes(),true))$type='other'; $amt=max(0,(float)($_POST['manual_cost_amount']??0)); if($desc===''||$amt<=0)throw new RuntimeException('Enter the direct-cost description and amount.');
                $sheet=crmEnsureActiveCostSheet($pdo,$tripId,$leadId,$adminId,'INR'); $sheetId=(int)$sheet['id']; $st=$pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM cost_sheet_items WHERE cost_sheet_id=?');$st->execute([$sheetId]);$sort=(int)$st->fetchColumn();
                $st=$pdo->prepare("INSERT INTO cost_sheet_items(cost_sheet_id,source_type,item_type,description,quantity,unit_cost,line_cost,sort_order,created_by_admin_id) VALUES(?,'manual',?,?,1,?,?,?,?)");$st->execute([$sheetId,$type,$desc,$amt,$amt,$sort,$adminId?:null]); crmRecalculateCostSheet($pdo,$sheetId);
                $_SESSION['admin_crm_success']='Direct cost added to the Trip Cost Sheet.'; crmRedirect(BASE_URL.'admin-crm.php?lead='.$leadId.'&trip='.$tripId.'#crm-costing');
            }
            if ($action === 'create_trip') {
                $leadId = (int)($_POST['lead_id'] ?? 0);
                if ($leadId <= 0) throw new RuntimeException('Invalid enquiry.');
                $trip = crmCreateTripForEnquiry($pdo, $leadId, $adminId);
                $_SESSION['admin_crm_success'] = 'Trip ' . (string)$trip['trip_code'] . ' is ready.';
                crmRedirect(BASE_URL . 'admin-crm.php?lead=' . $leadId . '&trip=' . (int)$trip['id']);
            }

            if ($action === 'create_lead') {
                $name = crmPost('name');
                $email = crmPost('email');
                $mobile = crmPost('mobile');
                $destinationId = max(0, (int)($_POST['destination_id'] ?? 0));
                $destination = '';
                if ($destinationId > 0) {
                    $stmt = $pdo->prepare("
                        SELECT name
                        FROM destinations
                        WHERE id=? AND status='active'
                        LIMIT 1
                    ");
                    $stmt->execute([$destinationId]);
                    $destination = trim((string)($stmt->fetchColumn() ?: ''));
                    if ($destination === '') {
                        throw new RuntimeException('Select a valid destination from Destination Master.');
                    }
                }
                $travelDate = crmPost('travel_date');
                $adults = max(1, (int)($_POST['adults'] ?? 1));
                $children = max(0, (int)($_POST['children'] ?? 0));
                $budget = crmPost('budget');
                $message = crmPost('message');
                $source = crmPost('lead_source', 'manual');
                $priority = crmPost('priority', 'medium');
                $campaign = crmPost('campaign_name');
                $profileName = crmPost('social_profile_name');
                $profileUrl = crmPost('social_profile_url');
                $platformLeadId = crmPost('platform_lead_id');
                $score = max(0, min(100, (int)($_POST['lead_score'] ?? 50)));
                $followUp = crmSqlDateTime(crmPost('next_follow_up_at'));
                $tags = crmPost('tags');

                if ($name === '') {
                    throw new RuntimeException('Customer name is required.');
                }

                if ($email === '' && $mobile === '') {
                    throw new RuntimeException('Enter customer email or mobile number.');
                }

                if (!in_array($source, $sources, true)) {
                    throw new RuntimeException('Invalid lead source.');
                }

                if (!in_array($priority, $priorities, true)) {
                    throw new RuntimeException('Invalid priority.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO enquiries
                    (
                        name, email, mobile, destination, destination_id, travel_date,
                        adults, children, budget, message, status,
                        admin_notes, created_at, updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NULL, NOW(), NOW())
                ");

                $stmt->execute([
                    $name,
                    $email !== '' ? $email : null,
                    $mobile !== '' ? $mobile : null,
                    $destination !== '' ? $destination : null,
                    $destinationId > 0 ? $destinationId : null,
                    $travelDate !== '' ? $travelDate : null,
                    $adults,
                    $children,
                    $budget !== '' ? (float)$budget : null,
                    $message !== '' ? $message : null
                ]);

                $leadId = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare("
                    INSERT INTO crm_lead_meta
                    (
                        enquiry_id, assigned_admin_id, priority, lead_source,
                        campaign_name, social_profile_name, social_profile_url,
                        platform_lead_id, lead_score, estimated_value,
                        next_follow_up_at, last_contact_at, lost_reason, tags,
                        created_at, updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, NULL, ?, NOW(), NOW())
                ");

                $stmt->execute([
                    $leadId,
                    $adminId,
                    $priority,
                    $source,
                    $campaign !== '' ? $campaign : null,
                    $profileName !== '' ? $profileName : null,
                    $profileUrl !== '' ? $profileUrl : null,
                    $platformLeadId !== '' ? $platformLeadId : null,
                    $score,
                    $followUp,
                    $tags !== '' ? $tags : null
                ]);

                crmAddActivity(
                    $pdo,
                    $leadId,
                    $adminId,
                    'note',
                    'Lead created',
                    'Lead added from ' . crmSourceLabel($source),
                    $followUp
                );

                $pdo->commit();

                $_SESSION['admin_crm_success'] = 'New CRM lead created successfully.';
                crmRedirect(BASE_URL . 'admin-crm.php?lead=' . $leadId);
            }

            if ($action === 'save_lead') {
                $leadId = (int)($_POST['lead_id'] ?? 0);
                $status = crmPost('status');
                $priority = crmPost('priority');
                $source = crmPost('lead_source', 'website');
                $campaign = crmPost('campaign_name');
                $profileName = crmPost('social_profile_name');
                $profileUrl = crmPost('social_profile_url');
                $platformLeadId = crmPost('platform_lead_id');
                $score = max(0, min(100, (int)($_POST['lead_score'] ?? 50)));
                $estimated = crmPost('estimated_value');
                $followUp = crmSqlDateTime(crmPost('next_follow_up_at'));
                $tags = crmPost('tags');
                $lostReason = crmPost('lost_reason');
                $adminNotes = crmPost('admin_notes');

                if ($leadId <= 0) {
                    throw new RuntimeException('Invalid CRM lead.');
                }

                if (!in_array($status, $statuses, true)) {
                    throw new RuntimeException('Invalid lead status.');
                }

                if (!in_array($priority, $priorities, true)) {
                    throw new RuntimeException('Invalid priority.');
                }

                if (!in_array($source, $sources, true)) {
                    throw new RuntimeException('Invalid lead source.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT status FROM enquiries WHERE id = ? LIMIT 1");
                $stmt->execute([$leadId]);
                $oldStatus = $stmt->fetchColumn();

                if ($oldStatus === false) {
                    throw new RuntimeException('Lead not found.');
                }

                $stmt = $pdo->prepare("
                    UPDATE enquiries
                    SET status = ?, admin_notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $status,
                    $adminNotes !== '' ? $adminNotes : null,
                    $leadId
                ]);

                $stmt = $pdo->prepare("
                    INSERT INTO crm_lead_meta
                    (
                        enquiry_id, assigned_admin_id, priority, lead_source,
                        campaign_name, social_profile_name, social_profile_url,
                        platform_lead_id, lead_score, estimated_value,
                        next_follow_up_at, lost_reason, tags, created_at, updated_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        assigned_admin_id = VALUES(assigned_admin_id),
                        priority = VALUES(priority),
                        lead_source = VALUES(lead_source),
                        campaign_name = VALUES(campaign_name),
                        social_profile_name = VALUES(social_profile_name),
                        social_profile_url = VALUES(social_profile_url),
                        platform_lead_id = VALUES(platform_lead_id),
                        lead_score = VALUES(lead_score),
                        estimated_value = VALUES(estimated_value),
                        next_follow_up_at = VALUES(next_follow_up_at),
                        lost_reason = VALUES(lost_reason),
                        tags = VALUES(tags),
                        updated_at = NOW()
                ");

                $stmt->execute([
                    $leadId,
                    $adminId,
                    $priority,
                    $source,
                    $campaign !== '' ? $campaign : null,
                    $profileName !== '' ? $profileName : null,
                    $profileUrl !== '' ? $profileUrl : null,
                    $platformLeadId !== '' ? $platformLeadId : null,
                    $score,
                    $estimated !== '' ? max(0, (float)$estimated) : null,
                    $followUp,
                    $lostReason !== '' ? $lostReason : null,
                    $tags !== '' ? $tags : null
                ]);

                if ((string)$oldStatus !== $status) {
                    crmAddActivity(
                        $pdo,
                        $leadId,
                        $adminId,
                        'note',
                        'Lead status changed',
                        crmStatusLabel((string)$oldStatus) . ' → ' . crmStatusLabel($status),
                        $followUp
                    );
                }

                $pdo->commit();

                $_SESSION['admin_crm_success'] = 'CRM lead updated successfully.';
                crmRedirect(BASE_URL . 'admin-crm.php?lead=' . $leadId);
            }

            if ($action === 'add_activity') {
                $leadId = (int)($_POST['lead_id'] ?? 0);
                $type = crmPost('activity_type', 'note');
                $subject = crmPost('subject');
                $details = crmPost('details');
                $followUp = crmSqlDateTime(crmPost('follow_up_at'));

                crmAddActivity(
                    $pdo,
                    $leadId,
                    $adminId,
                    $type,
                    $subject,
                    $details,
                    $followUp
                );

                if (in_array($type, ['call', 'email', 'whatsapp', 'meeting', 'social_message'], true)) {
                    $stmt = $pdo->prepare("
                        UPDATE crm_lead_meta
                        SET last_contact_at = NOW(),
                            next_follow_up_at = COALESCE(?, next_follow_up_at),
                            updated_at = NOW()
                        WHERE enquiry_id = ?
                    ");
                    $stmt->execute([$followUp, $leadId]);
                }

                $_SESSION['admin_crm_success'] = 'CRM activity added successfully.';
                crmRedirect(BASE_URL . 'admin-crm.php?lead=' . $leadId . '#activities');
            }

            if ($action === 'complete_activity') {
                $leadId = (int)($_POST['lead_id'] ?? 0);
                $activityId = (int)($_POST['activity_id'] ?? 0);

                $stmt = $pdo->prepare("
                    UPDATE crm_activities
                    SET completed_at = NOW()
                    WHERE id = ? AND lead_id = ?
                ");
                $stmt->execute([$activityId, $leadId]);

                $_SESSION['admin_crm_success'] = 'Activity marked completed.';
                crmRedirect(BASE_URL . 'admin-crm.php?lead=' . $leadId . '#activities');
            }



            if ($action === 'accept_quotation_create_booking') {
                $leadId =
                    max(
                        0,
                        (int)($_POST['lead_id'] ?? 0)
                    );

                $tripId =
                    max(
                        0,
                        (int)($_POST['trip_id'] ?? 0)
                    );

                $versionId =
                    max(
                        0,
                        (int)($_POST['version_id'] ?? 0)
                    );

                $acceptedSource =
                    trim(
                        (string)(
                            $_POST['accepted_source']
                            ?? 'admin'
                        )
                    );

                $confirmationNote =
                    trim(
                        (string)(
                            $_POST['customer_confirmation_note']
                            ?? ''
                        )
                    );

                $result =
                    crmAcceptQuotationAndCreateBooking(
                        $pdo,
                        $leadId,
                        $versionId,
                        $tripId,
                        $adminId,
                        $acceptedSource,
                        $confirmationNote
                    );

                if (function_exists('tsCWMarkCustomerDecision')) {
                    tsCWMarkCustomerDecision($pdo, $tripId, $leadId, $versionId, 'accepted', $confirmationNote);
                }

                $_SESSION['admin_booking_details_success'] =
                    !empty($result['already_created'])
                        ? 'This accepted quotation is already linked to booking '
                            . (string)$result['booking_number']
                            . '.'
                        : 'Quotation accepted and booking '
                            . (string)$result['booking_number']
                            . ' created successfully.';

                crmRedirect(
                    BASE_URL
                    . 'admin-booking-details.php?id='
                    . (int)$result['booking_id']
                );
            }


            if ($action === 'save_package_version') {
                $leadId = (int)($_POST['lead_id'] ?? 0);

                if (function_exists('tsAclRequire')) {
                    tsAclRequire(
                        $pdo,
                        $adminId,
                        'edit_markup',
                        'save_quotation_version',
                        'enquiry',
                        $leadId
                    );
                }

                $postedTripId = max(0, (int)($_POST['trip_id'] ?? 0));
                $quotationTrip = null;

                /*
                |--------------------------------------------------------------------------
                | RESOLVE TRIP DURING THE SAVE ACTION
                |--------------------------------------------------------------------------
                |
                | $selectedTrip belongs to the page-rendering section below this POST
                | handler and is not guaranteed to exist while Save New Version is
                | being processed. Resolve the Trip here instead.
                |
                */

                if ($postedTripId > 0 && function_exists('crmTripById')) {
                    $tripCandidate = crmTripById($pdo, $postedTripId);

                    if (
                        $tripCandidate
                        && (int)($tripCandidate['enquiry_id'] ?? 0) === $leadId
                    ) {
                        $quotationTrip = $tripCandidate;
                    }
                }

                if (
                    !$quotationTrip
                    && $leadId > 0
                    && function_exists('crmTripByEnquiry')
                ) {
                    $quotationTrip = crmTripByEnquiry($pdo, $leadId);
                }

                $packageId = (int)($_POST['package_id'] ?? 0);
                $packageTitle = crmPost('package_title');
                $packageCode = crmPost('package_code');
                $destinationId = (int)($_POST['destination_id'] ?? 0);
                $packageType = crmPost('package_type', 'holiday');
                $shortDescription = crmPost('short_description');
                $packageDescription = crmPost('package_description');
                $durationDays = max(0, (int)($_POST['duration_days'] ?? 0));
                $durationNights = max(0, (int)($_POST['duration_nights'] ?? 0));
                $currency = strtoupper(crmPost('currency', 'INR'));
                $tourHighlights = crmPost('tour_highlights');
                $inclusions = crmPost('inclusions');
                $exclusions = crmPost('exclusions');
                $terms = crmPost('terms');
                $travelDate = crmPost('package_travel_date');
                $adultsCount = max(1, (int)($_POST['package_adults'] ?? 1));
                $childrenCount = max(0, (int)($_POST['package_children'] ?? 0));
                $basePrice = crmPost('base_price');
                $discountAmount = crmPost('discount_amount');

                if (
                    function_exists('tsAclRequire')
                    && (float)$discountAmount > 0
                ) {
                    tsAclRequire(
                        $pdo,
                        $adminId,
                        'approve_discount',
                        'approve_quotation_discount',
                        'enquiry',
                        $leadId,
                        [
                            'discount_amount' => (float)$discountAmount,
                        ]
                    );
                }

                $finalPrice = crmPost('final_price');
                $customerNotes = crmPost('customer_notes');
                $autoBuildHotels = (string)($_POST['quotation_auto_build_hotels'] ?? '1') === '1';
                $autoBuildTransport = (string)($_POST['quotation_auto_build_transport'] ?? '1') === '1';

                if ($leadId <= 0 || $packageTitle === '') {
                    throw new RuntimeException('Lead and package title are required.');
                }

                $mainImage = crmValidMediaPath(crmPost('existing_main_image')) ?? '';
                $uploadedMain = crmCustomerPackageUpload(
                    $_FILES['main_image'] ?? [],
                    'crm-main'
                );

                if ($uploadedMain !== null) {
                    $mainImage = $uploadedMain;
                }

                $gallery = array_values(array_filter(array_map(
                    static fn($value) => crmValidMediaPath(trim((string)$value)),
                    crmCustomerPackageArray('existing_gallery_images')
                )));

                foreach (crmCustomerPackageFiles('gallery_images') as $galleryFile) {
                    $savedGallery = crmCustomerPackageUpload($galleryFile, 'crm-gallery');

                    if ($savedGallery !== null) {
                        $gallery[] = $savedGallery;
                    }
                }

                $itineraryTitles = crmCustomerPackageArray('itinerary_title');
                $itineraryDescriptions = crmCustomerPackageArray('itinerary_description');
                $itineraryExistingImages = crmCustomerPackageArray('itinerary_existing_image');
                $itinerary = [];

                $itineraryCount = max(
                    count($itineraryTitles),
                    count($itineraryDescriptions),
                    count($itineraryExistingImages),
                    $durationDays
                );

                for ($index = 0; $index < $itineraryCount; $index++) {
                    $title = trim((string)($itineraryTitles[$index] ?? ''));
                    $description = trim((string)($itineraryDescriptions[$index] ?? ''));
                    $image = crmValidMediaPath(trim((string)($itineraryExistingImages[$index] ?? ''))) ?? '';

                    $file = [
                        'name' => $_FILES['itinerary_image']['name'][$index] ?? '',
                        'type' => $_FILES['itinerary_image']['type'][$index] ?? '',
                        'tmp_name' => $_FILES['itinerary_image']['tmp_name'][$index] ?? '',
                        'error' => $_FILES['itinerary_image']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['itinerary_image']['size'][$index] ?? 0
                    ];

                    $uploaded = crmCustomerPackageUpload($file, 'crm-day-' . ($index + 1));

                    if ($uploaded !== null) {
                        $image = $uploaded;
                    }

                    if ($title === '' && $description === '' && $image === '' && $index >= $durationDays) {
                        continue;
                    }

                    $itinerary[] = [
                        'day_number' => $index + 1,
                        'title' => $title,
                        'description' => $description,
                        'image' => $image
                    ];
                }

                if (!crmQuotationItineraryMeaningful(crmQuotationNormalizeItinerary($itinerary)) && $packageId>0 && crmTableExists($pdo,'package_itinerary_days')) {
                    $stmt=$pdo->prepare("SELECT day_number,title,description,image FROM package_itinerary_days WHERE package_id=? ORDER BY sort_order ASC,day_number ASC,id ASC");
                    $stmt->execute([$packageId]);
                    $masterItinerary=crmQuotationNormalizeItinerary($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
                    if (crmQuotationItineraryMeaningful($masterItinerary)) $itinerary=$masterItinerary;
                }

                /*
                 * AUTHORITATIVE AUTOMATIC PRICING
                 * --------------------------------
                 * Quotation price rows are never manually entered here.
                 * Rates are loaded from Package Master and calculated again on
                 * the server before saving the quotation version.
                 */
                $priceRows = [];
                $childPriceRows = [];
                $sourcePackage = null;

                if ($packageId > 0 && crmTableExists($pdo, 'packages')) {
                    $stmt = $pdo->prepare("
                        SELECT *
                        FROM packages
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$packageId]);
                    $sourcePackage = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                    if (!$sourcePackage) {
                        throw new RuntimeException('Selected Package Master was not found.');
                    }

                    if (crmTableExists($pdo, 'package_date_prices')) {
                        $stmt = $pdo->prepare("
                            SELECT
                                valid_from,
                                valid_to,
                                price_3_star,
                                price_4_star,
                                price_5_star
                            FROM package_date_prices
                            WHERE package_id = ?
                            ORDER BY valid_from ASC, sort_order ASC, id ASC
                        ");
                        $stmt->execute([$packageId]);
                        $priceRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }

                    if (crmTableExists($pdo, 'package_child_prices')) {
                        $stmt = $pdo->prepare("
                            SELECT age_from, age_to, price
                            FROM package_child_prices
                            WHERE package_id = ?
                            ORDER BY sort_order ASC, age_from ASC, id ASC
                        ");
                        $stmt->execute([$packageId]);
                        $childPriceRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }

                    if (
                        !$childPriceRows
                        && isset($sourcePackage['child_price'])
                        && (float)$sourcePackage['child_price'] > 0
                    ) {
                        $childPriceRows[] = [
                            'age_from' => (int)($sourcePackage['child_age_from'] ?? 0),
                            'age_to' => (int)($sourcePackage['child_age_to'] ?? 18),
                            'price' => (float)$sourcePackage['child_price']
                        ];
                    }
                }

                $hotelRateCategory =
                    in_array(crmPost('pricing_hotel_category', '4'), ['3','4','5'], true)
                        ? crmPost('pricing_hotel_category', '4')
                        : '4';

                $adultUnitRate = 0.0;

                foreach ($priceRows as $row) {
                    $from = (string)($row['valid_from'] ?? '');
                    $to = (string)($row['valid_to'] ?? '');

                    if (
                        $travelDate !== ''
                        && $from !== ''
                        && $to !== ''
                        && $travelDate >= $from
                        && $travelDate <= $to
                    ) {
                        $key = 'price_' . $hotelRateCategory . '_star';
                        $candidate = (float)($row[$key] ?? 0);

                        if ($candidate > 0) {
                            $adultUnitRate = $candidate;
                            break;
                        }
                    }
                }

                if (
                    $adultUnitRate <= 0
                    && is_array($sourcePackage)
                ) {
                    $adultUnitRate =
                        max(
                            0,
                            (float)(
                                $sourcePackage['sale_price']
                                ?? $sourcePackage['price']
                                ?? 0
                            )
                        );
                }

                $adultSubtotal =
                    $adultUnitRate * $adultsCount;

                $postedChildAges = crmCustomerPackageArray('child_age');
                $childAges = [];
                $childSubtotal = 0.0;

                if ($childrenCount > 0) {
                    if (count($postedChildAges) < $childrenCount) {
                        throw new RuntimeException(
                            'Please enter the age of every child so the correct Package Master child rate can be calculated.'
                        );
                    }

                    for ($index = 0; $index < $childrenCount; $index++) {
                        $rawAge = trim((string)($postedChildAges[$index] ?? ''));

                        if (
                            $rawAge === ''
                            || !ctype_digit($rawAge)
                            || (int)$rawAge < 0
                            || (int)$rawAge > 18
                        ) {
                            throw new RuntimeException('Every child age must be between 0 and 18.');
                        }

                        $age = (int)$rawAge;
                        $childAges[] = $age;
                        $matchedChildRate = null;

                        foreach ($childPriceRows as $childRow) {
                            $ageFrom = (int)($childRow['age_from'] ?? 0);
                            $ageTo = (int)($childRow['age_to'] ?? 18);

                            if ($age >= $ageFrom && $age <= $ageTo) {
                                $matchedChildRate = max(0, (float)($childRow['price'] ?? 0));
                                break;
                            }
                        }

                        if ($matchedChildRate === null) {
                            throw new RuntimeException(
                                'No Package Master child price is configured for age '
                                . $age
                                . '. Please update the package child pricing first.'
                            );
                        }

                        $childSubtotal += $matchedChildRate;
                    }
                }

                $packageAutoTotal =
                    max(
                        0,
                        $adultSubtotal + $childSubtotal
                    );

                $supplierId =
                    max(
                        0,
                        (int)($_POST['costing_supplier_id'] ?? 0)
                    );

                $supplierRate =
                    max(
                        0,
                        (float)($_POST['supplier_rate'] ?? 0)
                    );

                $supplierName = '';
                $supplierCurrency = '';
                $usingApprovedSupplierQuotes = false;
                $usingTripCostSheet = false;
                $quotationCostSheet = null;
                if (
                    $quotationTrip
                    && function_exists('crmActiveCostSheet')
                    && function_exists('crmCostSheetReady')
                    && crmCostSheetReady($pdo)
                ) {
                    $quotationCostSheet =
                        crmActiveCostSheet(
                            $pdo,
                            (int)$quotationTrip['id']
                        );
                    if (
                        $quotationCostSheet
                        && (float)($quotationCostSheet['travscope_cost_total'] ?? 0) > 0
                    ) {
                        $usingTripCostSheet = true;
                        $supplierRate =
                            max(
                                0,
                                (float)$quotationCostSheet['travscope_cost_total']
                            );
                        $supplierCurrency =
                            (string)($quotationCostSheet['currency'] ?? 'INR');

                        $names =
                            function_exists('crmCostSheetSupplierNames')
                                ? crmCostSheetSupplierNames(
                                    $pdo,
                                    (int)$quotationCostSheet['id']
                                )
                                : [];

                        $supplierName =
                            $names
                                ? implode(', ', $names)
                                : 'Trip Cost Sheet';

                        $supplierId = 0;
                    }
                }
                $approvedSupplierCosting =
                    crmSelectedSupplierCosting(
                        $pdo,
                        $leadId
                    );

                /*
                |--------------------------------------------------------------------------
                | AUTHORITATIVE SELECTED SUPPLIER COSTING
                |--------------------------------------------------------------------------
                |
                | If Supplier Sourcing contains selected quotes, those selected
                | commercial records become the authoritative internal cost.
                | Manual supplier rate entry is used only when no supplier quote
                | has been selected.
                |
                */

                if (!$usingTripCostSheet && !empty($approvedSupplierCosting['rows'])) {
                    if (empty($approvedSupplierCosting['valid'])) {
                        throw new RuntimeException(
                            (string)(
                                $approvedSupplierCosting['error']
                                ?? 'Selected supplier costing is not valid.'
                            )
                        );
                    }

                    $usingApprovedSupplierQuotes = true;
                    $supplierRate =
                        max(
                            0,
                            (float)(
                                $approvedSupplierCosting['total_supplier_cost']
                                ?? 0
                            )
                        );

                    if ($supplierRate <= 0) {
                        throw new RuntimeException(
                            'The selected supplier quote total is zero. Review the supplier costing before saving this quotation version.'
                        );
                    }

                    $supplierName =
                        implode(
                            ', ',
                            (array)(
                                $approvedSupplierCosting['supplier_names']
                                ?? []
                            )
                        );

                    $supplierCurrency =
                        (string)(
                            $approvedSupplierCosting['currency']
                            ?? ''
                        );

                    $supplierIds =
                        array_values(
                            array_unique(
                                array_filter(
                                    array_map(
                                        static fn(array $row): int =>
                                            (int)($row['supplier_id'] ?? 0),
                                        (array)$approvedSupplierCosting['rows']
                                    )
                                )
                            )
                        );

                    $supplierId =
                        count($supplierIds) === 1
                            ? (int)$supplierIds[0]
                            : 0;
                }

                /*
                 * Manual supplier fallback remains intact for quotations where
                 * no supplier RFQ quote has been selected yet.
                 */
                if (!$usingTripCostSheet && !$usingApprovedSupplierQuotes && $supplierId > 0) {
                    $stmt = $pdo->prepare("
                        SELECT
                            id,
                            company_name,
                            default_currency
                        FROM suppliers
                        WHERE id = ?
                          AND status = 'active'
                        LIMIT 1
                    ");
                    $stmt->execute([$supplierId]);
                    $selectedSupplier = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$selectedSupplier) {
                        throw new RuntimeException('Selected supplier is not active or no longer exists.');
                    }

                    if ($destinationId > 0 && crmTableExists($pdo, 'supplier_destinations')) {
                        $stmt = $pdo->prepare("
                            SELECT 1
                            FROM supplier_destinations
                            WHERE supplier_id = ?
                              AND destination_id = ?
                            LIMIT 1
                        ");
                        $stmt->execute([$supplierId, $destinationId]);

                        if (!$stmt->fetchColumn()) {
                            throw new RuntimeException(
                                'Selected supplier is not mapped to this package destination.'
                            );
                        }
                    }

                    if ($supplierRate <= 0) {
                        throw new RuntimeException(
                            'Enter the supplier quoted package rate for the selected supplier.'
                        );
                    }

                    $supplierName =
                        (string)$selectedSupplier['company_name'];

                    $supplierCurrency =
                        (string)($selectedSupplier['default_currency'] ?? '');
                }

                $costBase =
                    (
                        $usingTripCostSheet
                        ||
                        $usingApprovedSupplierQuotes
                        ||
                        ($supplierId > 0 && $supplierRate > 0)
                    )
                        ? $supplierRate
                        : $packageAutoTotal;

                $commissionType =
                    in_array(
                        crmPost('admin_commission_type', 'fixed'),
                        ['fixed', 'percent'],
                        true
                    )
                        ? crmPost('admin_commission_type', 'fixed')
                        : 'fixed';

                $commissionValue =
                    max(
                        0,
                        (float)($_POST['admin_commission_value'] ?? 0)
                    );

                $commissionAmount =
                    $commissionType === 'percent'
                        ? ($costBase * $commissionValue / 100)
                        : $commissionValue;

                $discountValue =
                    max(
                        0,
                        (float)($_POST['discount_amount'] ?? 0)
                    );

                $finalCalculatedPrice =
                    max(
                        0,
                        $costBase
                        + $commissionAmount
                        - $discountValue
                    );

                /*
                 * Existing crm_package_versions.base_price remains the Package
                 * Master calculated customer reference price. Supplier cost and
                 * commission remain internal inside the version snapshot.
                 */
                $basePrice =
                    number_format(
                        $packageAutoTotal,
                        2,
                        '.',
                        ''
                    );

                $discountAmount =
                    number_format(
                        $discountValue,
                        2,
                        '.',
                        ''
                    );

                $finalPrice =
                    number_format(
                        $finalCalculatedPrice,
                        2,
                        '.',
                        ''
                    );

                $quotationServiceSnapshot = crmQuotationServiceSnapshot($pdo, $quotationTrip);
                $travelSuggestionSnapshot = function_exists('tsTravelSuggestionSnapshot')
                    ? tsTravelSuggestionSnapshot($pdo, $leadId, (int)($quotationTrip['id'] ?? 0))
                    : ['flights'=>[], 'trains'=>[]];

                $snapshot = [
                    'source_package_id' => $packageId > 0 ? $packageId : null,
                    'destination_id' => $destinationId > 0 ? $destinationId : null,
                    'package_code' => $packageCode,
                    'package_type' => $packageType,
                    'short_description' => $shortDescription,
                    'description' => $packageDescription,
                    'duration_days' => $durationDays,
                    'duration_nights' => $durationNights,
                    'currency' => $currency,
                    'main_image' => $mainImage,
                    'gallery_images' => array_values(array_unique($gallery)),
                    'tour_highlights' => $tourHighlights,
                    'inclusions' => $inclusions,
                    'exclusions' => $exclusions,
                    'terms' => $terms,
                    'itinerary_days' => $itinerary,
                    'date_prices' => $priceRows,
                    'child_prices' => $childPriceRows,
                    'quotation_services' => $quotationServiceSnapshot,
                    'flight_suggestions' => (array)($travelSuggestionSnapshot['flights'] ?? []),
                    'train_suggestions' => (array)($travelSuggestionSnapshot['trains'] ?? []),
                    'service_build_preferences' => [
                        'hotels' => $autoBuildHotels,
                        'transport' => $autoBuildTransport,
                    ],
                    'pricing' => [
                        'hotel_category' => $hotelRateCategory,
                        'adult_unit_rate' => $adultUnitRate,
                        'adult_subtotal' => $adultSubtotal,
                        'child_ages' => $childAges,
                        'child_subtotal' => $childSubtotal,
                        'package_auto_total' => $packageAutoTotal,
                    ],
                    /*
                     * INTERNAL ONLY — never render these supplier/commission
                     * values in customer-facing quotation/PDF/email output.
                     */
                    'internal_costing' => [
                        'supplier_id' => $supplierId > 0 ? $supplierId : null,
                        'supplier_name' => $supplierName,
                        'supplier_currency' => $supplierCurrency,
                        /*
                         * supplier_rate is the TOTAL SUPPLIER PAYABLE used as
                         * the internal cost base. For selected RFQ quotes this
                         * already includes supplier GST/tax and other charges.
                         */
                        'supplier_rate' => $supplierRate,
                        'cost_source' =>
                            $usingTripCostSheet
                                ? 'trip_cost_sheet'
                                : (
                                    $usingApprovedSupplierQuotes
                                        ? 'selected_supplier_quotes'
                                        : (
                                            $supplierId > 0 && $supplierRate > 0
                                                ? 'manual_supplier_rate'
                                                : 'package_master'
                                        )
                                ),
                        'cost_sheet_id' => $usingTripCostSheet ? (int)($quotationCostSheet['id'] ?? 0) : null,
                        'costing_mode' =>
                            $usingApprovedSupplierQuotes
                                ? (string)($approvedSupplierCosting['mode'] ?? 'complete_package')
                                : (
                                    $supplierId > 0 && $supplierRate > 0
                                        ? 'manual_supplier_rate'
                                        : 'package_master'
                                ),
                        'supplier_net_amount' =>
                            $usingApprovedSupplierQuotes
                                ? (float)($approvedSupplierCosting['net_amount'] ?? 0)
                                : $supplierRate,
                        'supplier_tax_amount' =>
                            $usingApprovedSupplierQuotes
                                ? (float)($approvedSupplierCosting['tax_amount'] ?? 0)
                                : 0,
                        'supplier_other_amount' =>
                            $usingApprovedSupplierQuotes
                                ? (float)($approvedSupplierCosting['other_amount'] ?? 0)
                                : 0,
                        'supplier_quote_count' =>
                            $usingApprovedSupplierQuotes
                                ? (int)($approvedSupplierCosting['quote_count'] ?? 0)
                                : 0,
                        'selected_supplier_quotes' =>
                            $usingApprovedSupplierQuotes
                                ? (array)($approvedSupplierCosting['rows'] ?? [])
                                : [],
                        'commission_type' => $commissionType,
                        'commission_value' => $commissionValue,
                        'commission_amount' => $commissionAmount,
                        'discount_amount' => $discountValue,
                        'final_customer_price' => $finalCalculatedPrice,
                    ],
                    'customer_notes' => $customerNotes
                ];

                $versionNumber = crmPackageVersionNumber($pdo, $leadId);

                $stmt = $pdo->prepare("
                    INSERT INTO crm_package_versions
                    (
                        lead_id, package_id, version_number, package_title,
                        package_description, itinerary, inclusions, exclusions,
                        hotel_category, travel_date, adults, children,
                        base_price, discount_amount, final_price, custom_notes,
                        created_by, created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                $stmt->execute([
                    $leadId,
                    $packageId > 0 ? $packageId : null,
                    $versionNumber,
                    $packageTitle,
                    $packageDescription !== '' ? $packageDescription : null,
                    json_encode($itinerary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $inclusions !== '' ? $inclusions : null,
                    $exclusions !== '' ? $exclusions : null,
                    $hotelRateCategory . ' Star',
                    $travelDate !== '' ? $travelDate : null,
                    $adultsCount,
                    $childrenCount,
                    $basePrice !== '' ? (float)$basePrice : null,
                    $discountAmount !== '' ? max(0, (float)$discountAmount) : 0,
                    $finalPrice !== '' ? max(0, (float)$finalPrice) : null,
                    json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $adminId
                ]);

                $newQuotationVersionId = (int)$pdo->lastInsertId();

                if (function_exists('tsTravelTableExists')) {
                    try {
                        $quotationTripIdForSuggestions = (int)($quotationTrip['id'] ?? 0);
                        if ($quotationTripIdForSuggestions > 0 && tsTravelTableExists($pdo,'itinerary_flights')) {
                            $pdo->prepare("UPDATE itinerary_flights SET quotation_version_id=? WHERE trip_id=? AND status<>'cancelled'")
                                ->execute([$newQuotationVersionId,$quotationTripIdForSuggestions]);
                        }
                        if ($quotationTripIdForSuggestions > 0 && tsTravelTableExists($pdo,'itinerary_trains')) {
                            $pdo->prepare("UPDATE itinerary_trains SET quotation_version_id=? WHERE trip_id=? AND status<>'cancelled'")
                                ->execute([$newQuotationVersionId,$quotationTripIdForSuggestions]);
                        }
                    } catch (Throwable $travelSuggestionLinkError) {
                        error_log('Quotation travel suggestion link: '.$travelSuggestionLinkError->getMessage());
                    }
                }

                /* STEP 11F: create the destination/night hotel plan for this exact quotation version. */
                if ($autoBuildHotels && function_exists('tsHABuildForQuotation') && $quotationTrip) {
                    try {
                        tsHABuildForQuotation(
                            $pdo,
                            (int)$quotationTrip['id'],
                            $newQuotationVersionId,
                            $hotelRateCategory,
                            $travelDate !== '' ? $travelDate : null,
                            $itinerary,
                            $adminId,
                            false
                        );
                    } catch (Throwable $hotelAllocationError) {
                        error_log('Quotation hotel allocation build: ' . $hotelAllocationError->getMessage());
                    }
                }

                /* STEP 11G: build supplier-contract transport plan for this quotation. */
                if ($autoBuildTransport && function_exists('tsTABuildForQuotation') && $quotationTrip) {
                    try {
                        tsTABuildForQuotation($pdo, (int)$quotationTrip['id'], $newQuotationVersionId, $adminId, false);
                    } catch (Throwable $transportAllocationError) {
                        error_log('Quotation transport allocation build: ' . $transportAllocationError->getMessage());
                    }
                }

                if (function_exists('tsCWMarkQuotationPrepared') && $quotationTrip) {
                    tsCWMarkQuotationPrepared(
                        $pdo,
                        (int)$quotationTrip['id'],
                        $leadId,
                        $newQuotationVersionId,
                        $adminId
                    );
                }

                crmAddActivity(
                    $pdo,
                    $leadId,
                    $adminId,
                    'quotation',
                    'Customer package Version ' . $versionNumber . ' created',
                    $packageTitle . ' — master package was not changed.',
                    null
                );

                $_SESSION['admin_crm_success'] =
                    'Customer Package Version ' . $versionNumber
                    . ' saved. Hotel, vehicle and quotation services are ready below for this exact version.';

                crmRedirect(
                    BASE_URL . 'admin-crm.php?lead=' . $leadId
                    . '&quote_version=' . $newQuotationVersionId
                    . '#quotationVersion-' . $newQuotationVersionId
                );
            }

            if ($action === 'send_version_reply') {

                $leadId =
                    (int)(
                        $_POST['lead_id']
                        ?? 0
                    );

                $versionId =
                    (int)(
                        $_POST['version_id']
                        ?? 0
                    );

                $replyMessage =
                    trim(
                        (string)(
                            $_POST['reply_message']
                            ?? ''
                        )
                    );

                if (
                    $leadId <= 0
                    ||
                    $versionId <= 0
                ) {
                    throw new RuntimeException(
                        'Invalid quotation reply request.'
                    );
                }

                if ($replyMessage === '') {
                    throw new RuntimeException(
                        'Please enter a reply message.'
                    );
                }

                if (
                    mb_strlen(
                        $replyMessage
                    ) > 5000
                ) {
                    throw new RuntimeException(
                        'Reply message is too long.'
                    );
                }

                $stmt =
                    $pdo->prepare("
                        SELECT
                            v.id,
                            v.version_number,
                            v.package_title,
                            e.name AS customer_name,
                            e.email AS customer_email,
                            e.mobile AS customer_mobile
                        FROM crm_package_versions v
                        INNER JOIN enquiries e
                            ON e.id = v.lead_id
                        WHERE v.id = ?
                          AND v.lead_id = ?
                        LIMIT 1
                    ");

                $stmt->execute([
                    $versionId,
                    $leadId
                ]);

                $replyVersion =
                    $stmt->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!$replyVersion) {
                    throw new RuntimeException(
                        'Quotation version not found.'
                    );
                }

                $customerEmail =
                    trim(
                        (string)(
                            $replyVersion[
                                'customer_email'
                            ]
                            ?? ''
                        )
                    );

                if (
                    $customerEmail === ''
                    ||
                    !filter_var(
                        $customerEmail,
                        FILTER_VALIDATE_EMAIL
                    )
                ) {
                    throw new RuntimeException(
                        'Customer email is not available.'
                    );
                }

                $replySiteName =
                    function_exists('getSetting')
                        ? getSetting(
                            $pdo,
                            'site_name',
                            'TRAVSCOPE.COM'
                        )
                        : 'TRAVSCOPE.COM';
                $subject =
                    'Re: '
                    . crmQuotationThreadTag(
                        $versionId
                    )
                    . ' '
                    . $replySiteName
                    . ' | '
                    . (string)$replyVersion[
                        'package_title'
                    ];

                if (
                    !crmSendDirectReplyEmail(
                        $customerEmail,
                        $subject,
                        $replyMessage,
                        (string)(
                            $replyVersion[
                                'customer_name'
                            ]
                            ?? ''
                        ),
                        $replySiteName
                    )
                ) {
                    throw new RuntimeException(
                        'Unable to send the customer reply email.'
                    );
                }

                crmStoreQuotationMessage(
                    $pdo,
                    $leadId,
                    $versionId,
                    'outbound',
                    defined('MAIL_FROM_ADDRESS')
                        ? (string)MAIL_FROM_ADDRESS
                        : (
                            defined('SMTP_USERNAME')
                                ? (string)SMTP_USERNAME
                                : ''
                        ),
                    $customerEmail,
                    $subject,
                    $replyMessage
                );

                crmAddActivity(
                    $pdo,
                    $leadId,
                    $adminId,
                    'email',
                    'Reply sent for Quotation Version '
                    . (int)$replyVersion[
                        'version_number'
                    ],
                    $replyMessage,
                    null
                );

                $_SESSION[
                    'admin_crm_success'
                ] =
                    'Reply sent successfully to '
                    . $customerEmail
                    . '.';

                crmRedirect(
                    BASE_URL
                    . 'admin-crm.php?lead='
                    . $leadId
                    . '#packageProposal'
                );
            }


            if ($action === 'send_package_email') {
                $leadId = (int)($_POST['lead_id'] ?? 0);
                $versionId = (int)($_POST['version_id'] ?? 0);

                if (function_exists('tsAclRequire')) {
                    tsAclRequire(
                        $pdo,
                        $adminId,
                        'send_quotation',
                        'send_customer_quotation',
                        'quotation_version',
                        $versionId,
                        [
                            'lead_id' => $leadId,
                        ]
                    );
                }

                $stmt = $pdo->prepare("
                    SELECT
                        v.*,
                        e.name AS customer_name,
                        e.email AS customer_email,
                        e.mobile AS customer_mobile,
                        e.destination AS customer_destination
                    FROM crm_package_versions v
                    INNER JOIN enquiries e ON e.id = v.lead_id
                    WHERE v.id = ? AND v.lead_id = ?
                    LIMIT 1
                ");

                $stmt->execute([$versionId, $leadId]);
                $version = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$version) {
                    throw new RuntimeException('Package version not found.');
                }

                if (empty($version['customer_email'])) {
                    throw new RuntimeException('Customer email is not available.');
                }

                $siteNameForMail = function_exists('getSetting')
                    ? getSetting($pdo, 'site_name', 'TRAVSCOPE.COM')
                    : 'TRAVSCOPE.COM';

                $snapshot = crmCustomerPackageSnapshot(
                    $version['custom_notes'] ?? ''
                );
                $snapshot = crmHydrateQuotationSnapshot($pdo,$version,$snapshot);

                $pdfUrl =
                    BASE_URL
                    . 'admin-crm.php?lead='
                    . $leadId
                    . '&quotation_pdf=1&version='
                    . $versionId
                    . '&token='
                    . rawurlencode(
                        crmQuotationPublicToken(
                            $leadId,
                            $versionId
                        )
                    );

                $html = crmProfessionalQuotationHtml(
                    $version,
                    $version,
                    $snapshot,
                    $siteNameForMail,
                    $currencySymbol,
                    $pdfUrl
                );

                $plain = crmProfessionalQuotationPlain(
                    $version,
                    $version,
                    $snapshot,
                    $siteNameForMail,
                    $currencySymbol,
                    $pdfUrl
                );

                $subject =
                    crmQuotationThreadTag(
                        $versionId
                    )
                    . ' '
                    . $siteNameForMail
                    . ' | Your Personalized Travel Quotation - '
                    . (string)$version['package_title'];

                $emailPdfHtml =
                    crmQuotationZenzHtml(
                        $version,
                        $snapshot,
                        $siteNameForMail,
                        $currencySymbol,
                        $leadId,
                        false,
                        true
                    );

                $pdfBytes =
                    crmRenderZenzQuotationPdf(
                        $emailPdfHtml,
                        $version,
                        $version,
                        $snapshot,
                        $siteNameForMail,
                        $currencySymbol
                    );

                $pdfFileName =
                    'TRAVSCOPE-Quotation-V'
                    . (int)$version['version_number']
                    . '-'
                    . preg_replace(
                        '/[^A-Za-z0-9_-]+/',
                        '-',
                        (string)$version['package_title']
                    )
                    . '.pdf';

                if (!crmSendProfessionalQuotationEmail(
                    (string)$version['customer_email'],
                    $subject,
                    $html,
                    $plain,
                    $pdfBytes,
                    $pdfFileName
                )) {
                    throw new RuntimeException(
                        'Unable to send the professional quotation email.'
                    );
                }

                crmStoreQuotationMessage(
                    $pdo,
                    $leadId,
                    $versionId,
                    'outbound',
                    defined('MAIL_FROM_ADDRESS')
                        ? (string)MAIL_FROM_ADDRESS
                        : (
                            defined('SMTP_USERNAME')
                                ? (string)SMTP_USERNAME
                                : ''
                        ),
                    (string)$version['customer_email'],
                    $subject,
                    'Quotation Version '
                    . (int)$version['version_number']
                    . ' sent with PDF attachment.'
                    . "\n\n"
                    . $plain
                );

                $stmt = $pdo->prepare("
                    UPDATE crm_package_versions
                    SET email_sent_at = NOW()
                    WHERE id = ?
                ");

                $stmt->execute([$versionId]);

                crmAddActivity(
                    $pdo,
                    $leadId,
                    $adminId,
                    'email',
                    'Professional Package Version '
                    . (int)$version['version_number']
                    . ' emailed with PDF',
                    (string)$version['package_title'],
                    null
                );

                if (function_exists('tsCWMarkQuotationSent')) {
                    $cwTripId = 0;
                    if (function_exists('crmTripByEnquiry')) {
                        $cwTrip = crmTripByEnquiry($pdo, $leadId);
                        $cwTripId = (int)($cwTrip['id'] ?? 0);
                    }
                    if ($cwTripId > 0) tsCWMarkQuotationSent($pdo,$cwTripId,$leadId,$versionId,$adminId,'email');
                }

                $_SESSION['admin_crm_success'] =
                    'Professional quotation email sent successfully with PDF attachment.';

                crmRedirect(
                    BASE_URL
                    . 'admin-crm.php?lead='
                    . $leadId
                    . '#packageProposal'
                );
            }

            if ($action === 'mark_package_whatsapp') {
                $leadId = (int)($_POST['lead_id'] ?? 0);
                $versionId = (int)($_POST['version_id'] ?? 0);

                if (function_exists('tsAclRequire')) {
                    tsAclRequire(
                        $pdo,
                        $adminId,
                        'send_quotation',
                        'share_customer_quotation_whatsapp',
                        'quotation_version',
                        $versionId,
                        ['lead_id' => $leadId]
                    );
                }

                $stmt = $pdo->prepare("
                    UPDATE crm_package_versions
                    SET whatsapp_shared_at = NOW()
                    WHERE id = ? AND lead_id = ?
                ");
                $stmt->execute([$versionId, $leadId]);

                crmAddActivity(
                    $pdo,
                    $leadId,
                    $adminId,
                    'whatsapp',
                    'Package proposal shared on WhatsApp',
                    'Version ID: ' . $versionId,
                    null
                );

                if (function_exists('tsCWMarkQuotationSent')) {
                    $cwTripId = 0;
                    if (function_exists('crmTripByEnquiry')) {
                        $cwTrip = crmTripByEnquiry($pdo, $leadId);
                        $cwTripId = (int)($cwTrip['id'] ?? 0);
                    }
                    if ($cwTripId > 0) tsCWMarkQuotationSent($pdo,$cwTripId,$leadId,$versionId,$adminId,'whatsapp');
                }

                $_SESSION['admin_crm_success'] = 'WhatsApp sharing recorded.';
                crmRedirect(BASE_URL . 'admin-crm.php?lead=' . $leadId . '#packageProposal');
            }

            if ($action === 'export_csv') {
                $rows = $pdo->query("
                    SELECT
                        e.id, e.name, e.email, e.mobile, e.destination,
                        e.travel_date, e.status, e.budget, e.created_at,
                        m.priority, m.lead_source, m.campaign_name,
                        m.social_profile_name, m.social_profile_url,
                        m.platform_lead_id, m.lead_score,
                        m.estimated_value, m.next_follow_up_at, m.tags
                    FROM enquiries e
                    LEFT JOIN crm_lead_meta m ON m.enquiry_id = e.id
                    ORDER BY e.id DESC
                ")->fetchAll(PDO::FETCH_ASSOC);

                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="travscope-crm-leads-' . date('Y-m-d') . '.csv"');

                echo "\xEF\xBB\xBF";

                $headings = [
                    'Lead ID', 'Name', 'Email', 'Mobile', 'Destination',
                    'Travel Date', 'Status', 'Budget', 'Created',
                    'Priority', 'Source', 'Campaign', 'Social Profile',
                    'Profile URL', 'Platform Lead ID', 'Score',
                    'Estimated Value', 'Next Follow Up', 'Tags'
                ];

                echo implode(',', array_map('crmCsvCell', $headings)) . "\n";

                foreach ($rows as $row) {
                    echo implode(',', array_map('crmCsvCell', array_values($row))) . "\n";
                }

                exit;
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('TRAVSCOPE Professional CRM: ' . $e->getMessage());
            $error =
                $e instanceof RuntimeException
                    ? $e->getMessage()
                    : (
                        $e instanceof PDOException
                            ? 'CRM database action failed: '
                                . preg_replace(
                                    '/\s+/',
                                    ' ',
                                    trim((string)$e->getMessage())
                                )
                            : (
                        'CRM action failed: '
                        . preg_replace(
                            '/\s+/',
                            ' ',
                            trim((string)$e->getMessage())
                        )
                    )
                    );
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$priorityFilter = trim((string)($_GET['priority'] ?? ''));
// Phase 0A: Trip URLs resolve back to the existing enquiry-based CRM without breaking old lead URLs.
$requestedTripId = max(0, (int)($_GET['trip'] ?? 0));
if ($requestedTripId > 0 && function_exists('crmTripById')) {
    $requestedTrip = crmTripById($pdo, $requestedTripId);
    if ($requestedTrip && !isset($_GET['lead'])) { $_GET['lead'] = (int)$requestedTrip['enquiry_id']; }
}

$sourceFilter = trim((string)($_GET['source'] ?? ''));
$view = trim((string)($_GET['view'] ?? 'pipeline'));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

if (!in_array($statusFilter, $statuses, true)) {
    $statusFilter = '';
}

if (!in_array($priorityFilter, $priorities, true)) {
    $priorityFilter = '';
}

if (!in_array($sourceFilter, $sources, true)) {
    $sourceFilter = '';
}

if (!in_array($view, ['list', 'pipeline'], true)) {
    $view = 'pipeline';
}
// Simplified CRM management: Pipeline is the single lead-management view.
$view = 'pipeline';

$stats = [
    'total' => 0,
    'today' => 0,
    'overdue' => 0,
    'converted' => 0,
    'value' => 0
];

$sourceStats = [];
$leads = [];
$pipeline = [];
$selectedLead = null;
$selectedTrip = null;
$tripAdminOptions = [];
$tripServiceRequirements = [];
$activeCostSheet = null;
$activeCostSheetItems = [];
$activities = [];
$availablePackages = [];
$crmDestinations = [];
$crmPackageTypes = [];
$packageVersions = [];
$quotationAcceptanceMap = [];
$bookingBridgeReady =
    function_exists('crmBookingBridgeReady')
    && crmBookingBridgeReady($pdo);
$totalRows = 0;
$totalPages = 1;

if ($crmReady) {
    try {
        $statsRow = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN DATE(e.created_at) = CURRENT_DATE() THEN 1 ELSE 0 END) AS today_count,
                SUM(CASE
                    WHEN m.next_follow_up_at < NOW()
                     AND e.status NOT IN ('converted', 'closed')
                    THEN 1 ELSE 0 END
                ) AS overdue_count,
                SUM(CASE WHEN e.status = 'converted' THEN 1 ELSE 0 END) AS converted_count,
                COALESCE(SUM(CASE WHEN e.status = 'converted' THEN m.estimated_value ELSE 0 END), 0) AS converted_value
            FROM enquiries e
            LEFT JOIN crm_lead_meta m ON m.enquiry_id = e.id
        ")->fetch(PDO::FETCH_ASSOC);

        $stats = [
            'total' => (int)($statsRow['total'] ?? 0),
            'today' => (int)($statsRow['today_count'] ?? 0),
            'overdue' => (int)($statsRow['overdue_count'] ?? 0),
            'converted' => (int)($statsRow['converted_count'] ?? 0),
            'value' => (float)($statsRow['converted_value'] ?? 0)
        ];

        $sourceStats = $pdo->query("
            SELECT
                COALESCE(m.lead_source, 'website') AS source,
                COUNT(*) AS total,
                SUM(CASE WHEN e.status = 'converted' THEN 1 ELSE 0 END) AS converted
            FROM enquiries e
            LEFT JOIN crm_lead_meta m ON m.enquiry_id = e.id
            GROUP BY COALESCE(m.lead_source, 'website')
            ORDER BY total DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = "(e.name LIKE ? OR e.email LIKE ? OR e.mobile LIKE ?
                OR e.destination LIKE ? OR e.message LIKE ?
                OR m.campaign_name LIKE ? OR m.social_profile_name LIKE ?
                OR m.platform_lead_id LIKE ? OR CAST(e.id AS CHAR) LIKE ?)";
            $term = '%' . $q . '%';
            for ($i = 0; $i < 9; $i++) {
                $params[] = $term;
            }
        }

        if ($statusFilter !== '') {
            $where[] = 'e.status = ?';
            $params[] = $statusFilter;
        }

        if ($priorityFilter !== '') {
            $where[] = "COALESCE(m.priority, 'medium') = ?";
            $params[] = $priorityFilter;
        }

        if ($sourceFilter !== '') {
            $where[] = "COALESCE(m.lead_source, 'website') = ?";
            $params[] = $sourceFilter;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM enquiries e
            LEFT JOIN crm_lead_meta m ON m.enquiry_id = e.id
            $whereSql
        ");
        $stmt->execute($params);
        $totalRows = (int)$stmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT
                e.*,
                COALESCE(m.priority, 'medium') AS priority,
                COALESCE(m.lead_source, 'website') AS lead_source,
                m.campaign_name, m.social_profile_name, m.social_profile_url,
                m.platform_lead_id, COALESCE(m.lead_score, 50) AS lead_score,
                m.estimated_value, m.next_follow_up_at, m.last_contact_at,
                m.lost_reason, m.tags,
                (SELECT MAX(a.created_at) FROM crm_activities a WHERE a.lead_id = e.id) AS latest_activity_at
            FROM enquiries e
            LEFT JOIN crm_lead_meta m ON m.enquiry_id = e.id
            $whereSql
            ORDER BY
                CASE COALESCE(m.priority, 'medium')
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    ELSE 4
                END,
                CASE
                    WHEN m.next_follow_up_at < NOW()
                     AND e.status NOT IN ('converted', 'closed') THEN 0
                    ELSE 1
                END,
                e.id DESC
            LIMIT $perPage OFFSET $offset
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($view === 'pipeline') {
            foreach ($statuses as $status) {
                $pipeline[$status] = [];
            }

            // Apply the visible search/status/priority/source controls to the actual board.
            // Keep the existing 250-row performance bound; the KPI numbers above remain global.
            $pipelineStmt = $pdo->prepare("
                SELECT
                    e.id, e.name, e.mobile, e.email, e.destination, e.status,
                    e.created_at, COALESCE(m.priority, 'medium') AS priority,
                    COALESCE(m.lead_source, 'website') AS lead_source,
                    COALESCE(m.lead_score, 50) AS lead_score,
                    m.estimated_value, m.next_follow_up_at
                FROM enquiries e
                LEFT JOIN crm_lead_meta m ON m.enquiry_id = e.id
                $whereSql
                ORDER BY e.id DESC
                LIMIT 250
            ");
            $pipelineStmt->execute($params);
            $pipelineRows = $pipelineStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($pipelineRows as $row) {
                $pipeline[$row['status']][] = $row;
            }
        }

        $selectedId = max(0, (int)($_GET['lead'] ?? 0));

        if ($selectedId > 0) {
            $stmt = $pdo->prepare("
                SELECT
                    e.*,
                    COALESCE(m.priority, 'medium') AS priority,
                    COALESCE(m.lead_source, 'website') AS lead_source,
                    m.campaign_name, m.social_profile_name, m.social_profile_url,
                    m.platform_lead_id, COALESCE(m.lead_score, 50) AS lead_score,
                    m.assigned_admin_id, m.estimated_value,
                    m.next_follow_up_at, m.last_contact_at,
                    m.lost_reason, m.tags
                FROM enquiries e
                LEFT JOIN crm_lead_meta m ON m.enquiry_id = e.id
                WHERE e.id = ?
                LIMIT 1
            ");
            $stmt->execute([$selectedId]);
            $selectedLead = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($selectedLead && function_exists('crmTripByEnquiry')) {
                $selectedTrip = crmTripByEnquiry($pdo, $selectedId);
                if ($selectedTrip && $requestedTripId <= 0) { $requestedTripId = (int)$selectedTrip['id']; }
                if ($selectedTrip && function_exists('crmTripServiceRequirements')) {
                    $tripServiceRequirements = crmTripServiceRequirements($pdo,(int)$selectedTrip['id']);
                    $activeCostSheet = crmActiveCostSheet($pdo,(int)$selectedTrip['id']);
                    if ($activeCostSheet) $activeCostSheetItems = crmCostSheetItems($pdo,(int)$activeCostSheet['id']);
                }
                try {
                    $tripAdminOptions = $pdo->query("SELECT id,name,role FROM admins WHERE status='active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable $e) { $tripAdminOptions = []; }
            }

            if ($selectedLead) {
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM crm_activities
                    WHERE lead_id = ?
                    ORDER BY created_at DESC, id DESC
                ");
                $stmt->execute([$selectedId]);
                $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (crmTableExists($pdo, 'packages')) {
                    $availablePackages = $pdo->query("
                        SELECT
                            p.*,
                            d.name AS destination_name
                        FROM packages p
                        LEFT JOIN destinations d ON d.id = p.destination_id
                        ORDER BY p.title ASC
                    ")->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($availablePackages as &$availablePackage) {
                        $packageIdForLoad = (int)$availablePackage['id'];

                        $stmt = $pdo->prepare("
                            SELECT image
                            FROM package_images
                            WHERE package_id = ?
                            ORDER BY sort_order ASC, id ASC
                        ");
                        $stmt->execute([$packageIdForLoad]);
                        $availablePackage['gallery_images'] =
                            $stmt->fetchAll(PDO::FETCH_COLUMN);

                        $stmt = $pdo->prepare("
                            SELECT day_number, title, description, image
                            FROM package_itinerary_days
                            WHERE package_id = ?
                            ORDER BY sort_order ASC, day_number ASC, id ASC
                        ");
                        $stmt->execute([$packageIdForLoad]);
                        $availablePackage['itinerary_days'] =
                            $stmt->fetchAll(PDO::FETCH_ASSOC);

                        $stmt = $pdo->prepare("
                            SELECT
                                valid_from, valid_to,
                                price_3_star, price_4_star, price_5_star
                            FROM package_date_prices
                            WHERE package_id = ?
                            ORDER BY valid_from ASC, sort_order ASC, id ASC
                        ");
                        $stmt->execute([$packageIdForLoad]);
                        $availablePackage['date_prices'] =
                            $stmt->fetchAll(PDO::FETCH_ASSOC);

                        /*
                         * Child pricing is copied from Package Master.
                         * Use the independent package_child_prices table when present,
                         * with backward-compatible fallback to old scalar fields.
                         */
                        $availablePackage['child_prices'] = [];

                        if (crmTableExists($pdo, 'package_child_prices')) {
                            $stmt = $pdo->prepare("
                                SELECT age_from, age_to, price
                                FROM package_child_prices
                                WHERE package_id = ?
                                ORDER BY sort_order ASC, age_from ASC, id ASC
                            ");
                            $stmt->execute([$packageIdForLoad]);
                            $availablePackage['child_prices'] =
                                $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        }

                        if (
                            !$availablePackage['child_prices']
                            && isset($availablePackage['child_price'])
                            && (float)$availablePackage['child_price'] > 0
                        ) {
                            $availablePackage['child_prices'][] = [
                                'age_from' => (int)($availablePackage['child_age_from'] ?? 0),
                                'age_to' => (int)($availablePackage['child_age_to'] ?? 18),
                                'price' => (float)$availablePackage['child_price']
                            ];
                        }
                    }
                    unset($availablePackage);

                    $crmDestinations = $pdo->query("
                        SELECT id, name, country
                        FROM destinations
                        ORDER BY name ASC
                    ")->fetchAll(PDO::FETCH_ASSOC);

                    if (crmTableExists($pdo, 'package_types')) {
                        $crmPackageTypes = $pdo->query("
                            SELECT *
                            FROM package_types
                            WHERE status = 'active'
                            ORDER BY sort_order ASC, name ASC
                        ")->fetchAll(PDO::FETCH_ASSOC);
                    }
                }

                /*
                 * Supplier Master integration for quotation costing.
                 * No supplier schema change is required.
                 */
                $crmSupplierOptions = [];

                /*
                 * All selected supplier quotes for this enquiry are now combined
                 * through one costing engine.
                 *
                 * Supported automatically:
                 * 1) Complete package supplier rate
                 * 2) Service-wise supplier costs
                 * 3) Hybrid package + extra services
                 */
                $crmApprovedSupplierCosting =
                    crmSelectedSupplierCosting(
                        $pdo,
                        $selectedId
                    );

                $crmApprovedSupplierQuotes =
                    $crmApprovedSupplierCosting['rows']
                    ?? [];

                /*
                 * V24.3 — one authoritative supplier cost source for the
                 * Quotation Builder UI and calculator.
                 *
                 * Contract/package rates are written to the active Trip Cost
                 * Sheet, while RFQ selections are exposed by
                 * crmSelectedSupplierCosting().  The old UI only read the RFQ
                 * object, which made a successfully-applied contract rate look
                 * like INR 0.00 in the quotation workspace.
                 */
                $crmQuotationCostTotal = 0.0;
                $crmQuotationCostCurrency = 'INR';
                $crmQuotationCostSupplierNames = [];
                $crmQuotationCostMode = '';
                $crmQuotationCostModeLabel = '';
                $crmQuotationCostValid = true;
                $crmQuotationCostSheetId = 0;

                if (
                    $activeCostSheet
                    && (float)($activeCostSheet['travscope_cost_total'] ?? 0) > 0
                ) {
                    $crmQuotationCostTotal =
                        max(0, (float)$activeCostSheet['travscope_cost_total']);
                    $crmQuotationCostCurrency =
                        strtoupper(trim((string)($activeCostSheet['currency'] ?? 'INR'))) ?: 'INR';
                    $crmQuotationCostSupplierNames =
                        function_exists('crmCostSheetSupplierNames')
                            ? crmCostSheetSupplierNames($pdo, (int)$activeCostSheet['id'])
                            : [];
                    $crmQuotationCostMode = 'trip_cost_sheet';
                    $crmQuotationCostModeLabel = 'Trip Cost Sheet';
                    $crmQuotationCostSheetId = (int)$activeCostSheet['id'];
                } elseif ((float)($crmApprovedSupplierCosting['total_supplier_cost'] ?? 0) > 0) {
                    $crmQuotationCostTotal =
                        max(0, (float)$crmApprovedSupplierCosting['total_supplier_cost']);
                    $crmQuotationCostCurrency =
                        strtoupper(trim((string)($crmApprovedSupplierCosting['currency'] ?? 'INR'))) ?: 'INR';
                    $crmQuotationCostSupplierNames =
                        array_values(array_filter(array_map('strval', (array)($crmApprovedSupplierCosting['supplier_names'] ?? []))));
                    $crmQuotationCostMode =
                        (string)($crmApprovedSupplierCosting['mode'] ?? 'selected_supplier_quotes');
                    $crmQuotationCostModeLabel =
                        (string)($crmApprovedSupplierCosting['mode_label'] ?? 'Selected Supplier Costing');
                    $crmQuotationCostValid =
                        !empty($crmApprovedSupplierCosting['valid']);
                }

                /*
                 * Backward-compatible single quote reference for any older UI
                 * code that expects one selected supplier quote.
                 */
                $crmApprovedSupplierQuote =
                    count($crmApprovedSupplierQuotes) === 1
                        ? $crmApprovedSupplierQuotes[0]
                        : null;

                if (
                    crmTableExists($pdo, 'suppliers')
                    && crmTableExists($pdo, 'supplier_destinations')
                ) {
                    $crmSupplierOptions =
                        $pdo->query("
                            SELECT
                                s.id,
                                s.supplier_code,
                                s.company_name,
                                s.priority,
                                s.default_currency,
                                GROUP_CONCAT(
                                    DISTINCT sd.destination_id
                                    ORDER BY sd.destination_id
                                    SEPARATOR ','
                                ) AS destination_ids
                            FROM suppliers s
                            LEFT JOIN supplier_destinations sd
                                ON sd.supplier_id = s.id
                            WHERE s.status = 'active'
                            GROUP BY
                                s.id,
                                s.supplier_code,
                                s.company_name,
                                s.priority,
                                s.default_currency
                            ORDER BY
                                CASE s.priority
                                    WHEN 'preferred' THEN 1
                                    WHEN 'regular' THEN 2
                                    ELSE 3
                                END,
                                s.company_name ASC
                        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }

                if (crmTableExists($pdo, 'crm_package_versions')) {
                    $stmt = $pdo->prepare("
                        SELECT *
                        FROM crm_package_versions
                        WHERE lead_id = ?
                        ORDER BY version_number DESC, id DESC
                    ");
                    $stmt->execute([$selectedId]);
                    $packageVersions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if ($bookingBridgeReady) {
                        $stmt = $pdo->prepare("
                            SELECT
                                a.*,
                                b.booking_number,
                                b.booking_status,
                                b.payment_status
                            FROM quotation_acceptances a
                            JOIN bookings b
                                ON b.id = a.booking_id
                            WHERE a.lead_id = ?
                            ORDER BY a.accepted_at DESC, a.id DESC
                        ");
                        $stmt->execute([$selectedId]);

                        foreach (
                            $stmt->fetchAll(PDO::FETCH_ASSOC)
                            ?: []
                            as $acceptedRow
                        ) {
                            $quotationAcceptanceMap[
                                (int)$acceptedRow['quotation_version_id']
                            ] = $acceptedRow;
                        }
                    }
                }
            }
        }

    } catch (Throwable $e) {
        error_log('CRM load error: ' . $e->getMessage());
        $error = 'Unable to load professional CRM data. Import the SQL upgrade file again.';
    }
}


/*
|--------------------------------------------------------------------------
| DIRECT QUOTATION PDF DOWNLOAD
|--------------------------------------------------------------------------
*/

if (!empty($_GET['quotation_pdf'])) {
    $leadId = max(0, (int)($_GET['lead'] ?? 0));
    $versionId = max(0, (int)($_GET['version'] ?? 0));

    /*
    |--------------------------------------------------------------------------
    | PUBLIC LINK SECURITY
    |--------------------------------------------------------------------------
    */

    if (
        empty($_SESSION['admin_id'])
        &&
        !crmQuotationPublicTokenValid(
            $leadId,
            $versionId,
            trim(
                (string)(
                    $_GET['token']
                    ?? ''
                )
            )
        )
    ) {
        http_response_code(403);
        exit('This quotation PDF link is invalid.');
    }

    $stmt = $pdo->prepare("
        SELECT
            v.*,
            e.name AS customer_name,
            e.email AS customer_email,
            e.mobile AS customer_mobile,
            e.destination AS customer_destination
        FROM crm_package_versions v
        INNER JOIN enquiries e ON e.id = v.lead_id
        WHERE v.id = ? AND v.lead_id = ?
        LIMIT 1
    ");

    $stmt->execute([$versionId, $leadId]);
    $pdfVersion = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pdfVersion) {
        http_response_code(404);
        exit('Quotation version not found.');
    }

    $pdfSnapshot = crmCustomerPackageSnapshot(
        $pdfVersion['custom_notes'] ?? ''
    );
    $pdfSnapshot = crmHydrateQuotationSnapshot($pdo,$pdfVersion,$pdfSnapshot);

    $pdfSiteName = function_exists('getSetting')
        ? getSetting($pdo, 'site_name', 'TRAVSCOPE.COM')
        : 'TRAVSCOPE.COM';

    $pdfHtml =
        crmQuotationZenzHtml(
            $pdfVersion,
            $pdfSnapshot,
            $pdfSiteName,
            $currencySymbol,
            $leadId,
            false,
            true
        );

    $pdfBytes =
        crmRenderZenzQuotationPdf(
            $pdfHtml,
            $pdfVersion,
            $pdfVersion,
            $pdfSnapshot,
            $pdfSiteName,
            $currencySymbol
        );

    $pdfFileName =
        'TRAVSCOPE-Quotation-V'
        . (int)$pdfVersion['version_number']
        . '-'
        . preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '-',
            (string)$pdfVersion['package_title']
        )
        . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdfFileName . '"');
    header('Content-Length: ' . strlen($pdfBytes));

    echo $pdfBytes;
    exit;
}

/*
|--------------------------------------------------------------------------
| PROFESSIONAL QUOTATION PREVIEW / PRINT PDF
|--------------------------------------------------------------------------
*/
if (!empty($_GET['quotation_preview'])) {

    $leadId =
        max(
            0,
            (int)(
                $_GET['lead']
                ?? 0
            )
        );

    $versionId =
        max(
            0,
            (int)(
                $_GET['version']
                ?? 0
            )
        );

    $stmt =
        $pdo->prepare("
            SELECT
                v.*,
                e.name AS customer_name,
                e.email AS customer_email,
                e.mobile AS customer_mobile,
                e.destination AS customer_destination
            FROM crm_package_versions v
            INNER JOIN enquiries e
                ON e.id = v.lead_id
            WHERE v.id = ?
              AND v.lead_id = ?
            LIMIT 1
        ");

    $stmt->execute([
        $versionId,
        $leadId
    ]);

    $qv =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if (!$qv) {
        http_response_code(404);
        exit('Quotation version not found.');
    }

    $qs =
        crmCustomerPackageSnapshot(
            $qv['custom_notes']
            ?? ''
        );
    $qs = crmHydrateQuotationSnapshot($pdo,$qv,$qs);

    $siteName =
        function_exists('getSetting')
            ? getSetting(
                $pdo,
                'site_name',
                'TRAVSCOPE.COM'
            )
            : 'TRAVSCOPE.COM';

    echo crmQuotationZenzHtml(
        $qv,
        $qs,
        $siteName,
        $currencySymbol,
        $leadId,
        true,
        false
    );

    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#07192d">
<title>Professional CRM | TRAVSCOPE Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
:root{--navy:#07192d;--blue:#0b74ff;--cyan:#00b8ff;--bg:#f3f6fa;--white:#fff;--text:#172235;--muted:#69788b;--border:#dce5ee;--green:#07885f;--orange:#c57308;--red:#d33f53;--purple:#6e55d5;--shadow:0 12px 35px rgba(7,25,45,.08)}
*{box-sizing:border-box;margin:0;padding:0}body{background:var(--bg);color:var(--text);font:14px/1.55 'DM Sans',sans-serif}a{color:inherit;text-decoration:none}button,input,select,textarea{font:inherit}
.topbar{min-height:72px;padding:0 3%;display:flex;align-items:center;justify-content:space-between;gap:20px;background:var(--navy);color:#fff}.brand{font:800 19px 'Manrope',sans-serif}.brand span{color:#55c6ff}.nav{display:flex;gap:5px;flex-wrap:wrap}.nav a{padding:10px 12px;border-radius:8px;color:#c3d1df;font-weight:700}.nav a:hover,.nav a.active{background:rgba(255,255,255,.11);color:#fff}
.page{width:min(1550px,96%);margin:25px auto 60px}.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:20px}.eyebrow{color:var(--blue);font-size:11px;font-weight:800;letter-spacing:.8px}.page-head h1{margin-top:4px;color:var(--navy);font:800 29px 'Manrope',sans-serif}.page-head p{margin-top:4px;color:var(--muted)}.head-actions{display:flex;gap:8px;flex-wrap:wrap}
.button{min-height:42px;padding:0 14px;border:0;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;gap:7px;background:var(--navy);color:#fff;font-weight:800;cursor:pointer}.button.blue{background:var(--blue)}.button.green{background:var(--green)}.button.light{border:1px solid var(--border);background:#fff;color:var(--text)}
.alert{padding:13px 15px;margin-bottom:15px;border-radius:10px}.alert-success{background:#e8f8f1;color:#087753}.alert-error{background:#fff0f2;color:#b7394b}
.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px}.stat{padding:17px;border:1px solid var(--border);border-radius:13px;background:#fff;box-shadow:var(--shadow)}.stat span{color:var(--muted);font-size:11px;font-weight:700}.stat strong{display:block;margin-top:6px;color:var(--navy);font:800 25px 'Manrope',sans-serif}
.card{border:1px solid var(--border);border-radius:14px;background:#fff;box-shadow:var(--shadow);overflow:hidden}.card-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px}.card-head h2{font:800 17px 'Manrope',sans-serif;color:var(--navy)}.card-body{padding:18px}
.filters{display:grid;grid-template-columns:minmax(220px,1.6fr) repeat(3,minmax(140px,.75fr)) auto;gap:9px;margin-bottom:16px}.control{width:100%;min-height:43px;padding:0 11px;border:1px solid var(--border);border-radius:8px;outline:0;background:#fff;color:var(--text)}textarea.control{min-height:105px;padding:10px;resize:vertical}.control:focus{border-color:#9ac8ff;box-shadow:0 0 0 3px rgba(11,116,255,.08)}
.view-tabs{display:flex;gap:7px;margin-bottom:16px}.view-tabs a{padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--muted);font-weight:800}.view-tabs a.active{border-color:var(--blue);background:#edf5ff;color:var(--blue)}
.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1250px}th{padding:11px 10px;background:#f7f9fc;color:#718095;font-size:10px;text-align:left;text-transform:uppercase}td{padding:12px 10px;border-top:1px solid #edf1f5;vertical-align:top}.strong{color:var(--navy);font-weight:800}.small{margin-top:3px;color:var(--muted);font-size:11px}.badge{display:inline-flex;padding:5px 8px;border-radius:30px;font-size:10px;font-weight:800}.status-new{background:#eaf3ff;color:#0b67c7}.status-contacted{background:#f2edff;color:#6345c7}.status-qualified{background:#e9faf3;color:#087952}.status-follow_up{background:#fff5dd;color:#a26906}.status-proposal{background:#fff0e5;color:#b75a00}.status-converted{background:#e5f8ee;color:#087548}.status-closed{background:#f2f3f5;color:#697584}.priority-urgent{background:#fff0f2;color:#c03749}.priority-high{background:#fff4e4;color:#ad6506}.priority-medium{background:#edf5ff;color:#1766b6}.priority-low{background:#eef6f0;color:#4c795c}
.source{display:inline-flex;align-items:center;gap:6px;font-weight:700}.score{font-weight:800}.score-hot{color:#d33f53}.score-warm{color:#c57308}.score-cold{color:#0b74ff}.actions{display:flex;gap:5px;flex-wrap:wrap}.action{min-height:32px;padding:0 9px;border:1px solid var(--border);border-radius:7px;display:inline-flex;align-items:center;justify-content:center;background:#fff;color:#566579;font-size:11px;font-weight:800}.action.manage{background:var(--blue);border-color:var(--blue);color:#fff}
.pipeline{display:grid;grid-template-columns:repeat(7,minmax(250px,1fr));gap:12px;overflow:auto;padding-bottom:8px}.pipe-column{border:1px solid var(--border);border-radius:12px;background:#f7f9fc;min-height:300px}.pipe-head{padding:12px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;font-weight:800}.pipe-body{padding:9px}.lead-card{margin-bottom:8px;padding:11px;border:1px solid var(--border);border-radius:10px;background:#fff}.lead-card h3{font-size:13px}.lead-card p{margin-top:4px;color:var(--muted);font-size:11px}.lead-card-bottom{margin-top:9px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.details-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(330px,.75fr);gap:16px;margin-bottom:18px}.info-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.info{padding:11px;border:1px solid var(--border);border-radius:9px;background:#f9fbfd}.info label{display:block;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase}.info div{margin-top:4px;font-weight:700}.contact-row{margin-top:13px;display:flex;gap:7px;flex-wrap:wrap}.contact{padding:9px 11px;border-radius:8px;background:#edf5ff;color:#0b67c7;font-weight:800}.contact.quotation{background:#fff0e5;color:#b75a00;border:1px solid #ffd4ad}
.field{margin-bottom:12px}.field label{display:block;margin-bottom:6px;color:#657386;font-size:12px;font-weight:800}.two-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px}.save{width:100%;background:var(--blue)}
.activity-layout{display:grid;grid-template-columns:390px 1fr;gap:18px}.timeline{display:flex;flex-direction:column;gap:10px;max-height:620px;overflow-y:auto;padding-right:7px;scrollbar-width:thin;scrollbar-color:#b8c7d8 #eef3f8}.timeline::-webkit-scrollbar{width:8px}.timeline::-webkit-scrollbar-track{background:#eef3f8;border-radius:20px}.timeline::-webkit-scrollbar-thumb{background:#b8c7d8;border-radius:20px}.activity{padding:13px;border:1px solid var(--border);border-radius:10px;background:#fafbfd}.activity-head{display:flex;justify-content:space-between;gap:10px}.activity h3{font-size:13px}.activity-meta{color:var(--muted);font-size:11px}.activity p{margin-top:7px;color:#57667a}.complete{margin-top:8px}
.modal{position:fixed;inset:0;z-index:3000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(4,18,34,.75)}.modal.open{display:flex}.modal-box{width:min(760px,96vw);max-height:92vh;overflow:auto;border-radius:16px;background:#fff}.modal-head{padding:17px 19px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between}.modal-head h2{font:800 18px 'Manrope',sans-serif}.modal-close{width:37px;height:37px;border:0;border-radius:8px;background:#eef3f8;cursor:pointer}.modal-body{padding:18px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:11px}.full{grid-column:1/-1}
.source-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:9px;margin-bottom:18px}.source-box{padding:12px;border:1px solid var(--border);border-radius:10px;background:#fff}.source-box strong{display:block;margin-top:4px;font-size:18px}.source-box small{color:var(--muted)}
.proposal-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(360px,.85fr);gap:18px}.version-list{max-height:700px;overflow-y:auto;padding-right:6px}.version-card{padding:14px;margin-bottom:10px;border:1px solid var(--border);border-radius:11px;background:#fafbfd}.version-head{display:flex;justify-content:space-between;gap:12px}.version-head h3{font-size:14px;color:var(--navy)}.version-meta{margin-top:5px;color:var(--muted);font-size:11px}.version-price{margin-top:9px;font:800 18px 'Manrope',sans-serif;color:var(--blue)}.version-actions{margin-top:11px;display:flex;gap:7px;flex-wrap:wrap}.version-detail{margin-top:10px;padding:10px;border-radius:8px;background:#fff;border:1px solid #e7edf3;color:#566579;white-space:pre-wrap}.package-editor-note{padding:10px 12px;margin-bottom:12px;border-radius:9px;background:#edf5ff;color:#245f9e;font-size:12px}
@media(max-width:1000px){.proposal-grid{grid-template-columns:1fr}}

.quotation-service-editor{margin-top:10px;border:1px solid #cfe0f7;border-radius:10px;background:#f8fbff;overflow:hidden}.quotation-service-editor>summary{cursor:pointer;list-style:none;padding:10px 11px;display:flex;align-items:center;justify-content:space-between;gap:8px;color:#0b4f91;font-size:10px;font-weight:900}.quotation-service-editor>summary::-webkit-details-marker{display:none}.quotation-service-badges{display:flex;gap:5px;flex-wrap:wrap}.quotation-service-badge{padding:3px 6px;border-radius:999px;background:#e0efff;color:#0b65c2;font-size:8px;font-weight:900}.quotation-service-body{padding:10px;border-top:1px solid #dbe8f6;background:#fff}.quotation-service-title{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:2px 0 7px;font-size:10px;font-weight:900;color:#172b45}.quotation-service-title a{color:#0b74ff;text-decoration:none;font-size:9px}.quotation-service-row{margin-bottom:8px;padding:8px;border:1px solid #e0e9f3;border-radius:9px;background:#fbfdff}.quotation-service-row:last-child{margin-bottom:0}.quotation-service-row-head{display:flex;align-items:center;justify-content:space-between;gap:7px;margin-bottom:6px;font-size:9px}.quotation-service-row-head strong{font-size:10px}.quotation-service-grid{display:grid;grid-template-columns:1.5fr .8fr 1fr 1fr;gap:6px;align-items:end}.quotation-service-grid.transport{grid-template-columns:1.5fr .8fr 1fr}.quotation-service-grid .control{min-height:32px!important;font-size:9px!important;padding:5px 7px!important}.quotation-service-grid label{display:block;margin-bottom:3px;font-size:8px;font-weight:800;color:#6b7d91;text-transform:uppercase}.quotation-service-save{min-height:32px!important;padding:0 9px!important;font-size:9px!important}.quotation-service-empty{padding:9px;border:1px dashed #cbd8e6;border-radius:8px;color:#6b7d91;font-size:9px;background:#f8fafc}.quotation-service-build{display:flex;gap:6px;align-items:center;justify-content:space-between}.quotation-service-build form{margin:0}@media(max-width:1200px){.quotation-service-grid,.quotation-service-grid.transport{grid-template-columns:1fr 1fr}}@media(max-width:700px){.quotation-service-grid,.quotation-service-grid.transport{grid-template-columns:1fr}}
.quotation-create-services .studio-section-body{display:grid;gap:10px}.quotation-create-service-note{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:11px;border:1px solid #cfe0f7;border-radius:10px;background:#f4f9ff}.quotation-create-service-note strong{display:block;color:#0b4f91;font-size:11px}.quotation-create-service-note span{display:block;margin-top:3px;color:#61758c;font-size:9px;line-height:1.5}.quotation-create-service-links{display:flex;gap:6px;flex-wrap:wrap}.quotation-create-service-links a{white-space:nowrap;text-decoration:none;border:1px solid #cfe0f7;background:#fff;color:#0b65c2;padding:7px 9px;border-radius:8px;font-size:9px;font-weight:900}.quotation-build-toggles{display:grid;grid-template-columns:1fr 1fr;gap:8px}.quotation-build-toggles label{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid #dbe7f4;border-radius:9px;background:#fbfdff;color:#274461;font-size:9px;font-weight:800}.quotation-trip-service-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.quotation-trip-service-card{display:flex;gap:8px;align-items:flex-start;padding:9px;border:1px solid #e1e9f2;border-radius:9px;background:#fff}.quotation-trip-service-icon{width:30px;height:30px;border-radius:8px;background:#eef6ff;color:#0b74ff;display:grid;place-items:center;flex:0 0 auto}.quotation-trip-service-card strong{display:block;font-size:9px}.quotation-trip-service-card small{display:block;margin-top:2px;color:#728297;font-size:8px}.quotation-trip-service-card p{margin:4px 0 0;color:#53657a;font-size:8px;line-height:1.35}.quotation-create-service-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;padding-top:2px}.quotation-create-service-footer span{color:#6a7b8f;font-size:8px;line-height:1.45}.quotation-other-services{display:grid;grid-template-columns:1fr 1fr;gap:6px}.quotation-other-service-row{position:relative;padding:8px;border:1px solid #e1e9f2;border-radius:8px;background:#fbfdff}.quotation-other-service-row strong{display:block;font-size:9px}.quotation-other-service-row small{display:block;color:#718196;font-size:8px;margin-top:2px}.quotation-other-service-row>span{position:absolute;right:7px;top:7px;padding:3px 5px;border-radius:999px;background:#eef6ff;color:#0b65c2;font-size:7px;font-weight:900}.quotation-other-service-row p{margin:5px 0 0;color:#5c6d81;font-size:8px;line-height:1.4}@media(max-width:900px){.quotation-build-toggles,.quotation-trip-service-grid,.quotation-other-services{grid-template-columns:1fr}.quotation-create-service-note,.quotation-create-service-footer{flex-direction:column}}
.package-studio-card{overflow:visible}.studio-head{align-items:flex-start;background:linear-gradient(135deg,#f8fbff,#fff)}.studio-head p{margin-top:4px;color:var(--muted);font-size:12px}.studio-eyebrow{color:var(--blue);font-size:10px;font-weight:800;letter-spacing:.8px}.safe-badge{padding:7px 10px;border-radius:30px;background:#e9faf3;color:#087952;font-size:11px;font-weight:800}.studio-layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(320px,.62fr);gap:18px;align-items:start}.studio-editor{min-width:0}.studio-toolbar{padding:13px;margin-bottom:12px;border:1px solid #cfe0f7;border-radius:11px;display:flex;align-items:end;gap:10px;background:#f5f9ff}.toolbar-package{flex:1;margin:0}.studio-section{margin-bottom:11px;border:1px solid var(--border);border-radius:11px;background:#fff;overflow:hidden}.studio-section>summary{padding:14px 16px;display:flex;align-items:center;gap:9px;background:#f8fafc;color:var(--navy);font-weight:800;cursor:pointer;list-style:none}.studio-section>summary::-webkit-details-marker{display:none}.studio-section>summary i{width:27px;height:27px;border-radius:8px;display:grid;place-items:center;background:#eaf4ff;color:var(--blue)}.studio-section[open]>summary{border-bottom:1px solid var(--border);background:#f4f8fd}.studio-section-body{padding:16px}.studio-large{min-height:145px!important}.file-control{height:auto!important;padding:9px!important}.image-editor-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.studio-image-preview{height:210px;margin-top:9px;border:1px dashed #bfd0e1;border-radius:10px;display:grid;place-items:center;overflow:hidden;background:#f5f8fb;color:var(--muted)}.studio-image-preview img{width:100%;height:100%;object-fit:cover}.gallery-preview-grid{margin-top:9px;display:grid;grid-template-columns:repeat(3,1fr);gap:7px}.gallery-preview-item{position:relative;height:90px;border-radius:8px;overflow:hidden;background:#edf2f7}.gallery-preview-item img{width:100%;height:100%;object-fit:cover}.gallery-preview-item button{position:absolute;top:5px;right:5px;width:25px;height:25px;border:0;border-radius:50%;background:rgba(255,255,255,.94);color:var(--red);cursor:pointer}.section-intro{margin-bottom:12px;padding:10px 12px;border-radius:8px;background:#edf5ff;color:#376b9f;font-size:12px}.pricing-table-wrap{overflow:auto;margin-bottom:10px}.pricing-table{min-width:760px}.pricing-table input{width:100%;min-height:39px;padding:0 8px;border:1px solid var(--border);border-radius:7px}.remove-row{width:34px;height:34px;border:0;border-radius:8px;background:#fff0f2;color:var(--red);cursor:pointer}.itinerary-editor{display:flex;flex-direction:column;gap:10px;max-height:660px;overflow-y:auto;padding-right:5px}.itinerary-edit-card{padding:13px;border:1px solid var(--border);border-radius:10px;background:#fafbfd}.itinerary-card-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.day-label{display:inline-flex;padding:6px 9px;border-radius:20px;background:var(--blue);color:#fff;font-size:11px;font-weight:800}.itinerary-card-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.itinerary-card-grid .full{grid-column:1/-1}.itinerary-preview{width:120px;height:78px;margin-top:7px;border-radius:8px;overflow:hidden;background:#edf2f7}.itinerary-preview img{width:100%;height:100%;object-fit:cover}.final-price-control{border-color:#8fbcff!important;background:#f2f7ff!important;color:var(--blue)!important;font-size:18px!important;font-weight:800}.studio-save-bar{position:sticky;bottom:10px;z-index:10;margin-top:12px;padding:13px 14px;border:1px solid #bfd7f5;border-radius:11px;display:flex;align-items:center;justify-content:space-between;gap:14px;background:rgba(255,255,255,.96);box-shadow:0 12px 35px rgba(7,25,45,.14);backdrop-filter:blur(10px)}.studio-save-bar strong,.studio-save-bar span{display:block}.studio-save-bar span{margin-top:2px;color:var(--muted);font-size:11px}.studio-save-button{min-width:180px}.version-panel{position:sticky;top:18px;border:1px solid var(--border);border-radius:12px;background:#f8fafc;overflow:hidden}.version-panel-head{padding:14px;border-bottom:1px solid var(--border);background:#fff}.version-panel-head span{color:var(--blue);font-size:9px;font-weight:800;letter-spacing:.7px}.version-panel-head h3{margin-top:3px;font:800 16px 'Manrope',sans-serif}.studio-version-list{max-height:790px;overflow-y:auto;padding:10px}.studio-version-card{padding:13px;margin-bottom:9px;border:1px solid var(--border);border-radius:10px;background:#fff}.studio-version-top{display:flex;justify-content:space-between;gap:8px}.version-number{color:var(--blue);font-size:10px;font-weight:800}.version-date{color:var(--muted);font-size:9px}.studio-version-card h4{margin-top:7px;font-size:14px}.version-facts{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;color:var(--muted);font-size:10px}.version-price{margin-top:9px;color:var(--navy);font:800 21px 'Manrope',sans-serif}.snapshot-summary{padding-top:9px}.snapshot-summary p{margin:6px 0;font-size:11px}.whatsapp-action{border-color:#bde7d2!important;background:#eafaf2!important;color:#087952!important}.empty-version-state{padding:45px 16px;text-align:center;color:var(--muted)}.empty-version-state i{font-size:35px;color:#9cb2c8}.empty-version-state h4{margin-top:10px;color:var(--navy)}.empty-version-state p{margin-top:5px;font-size:11px}
@media(max-width:1180px){.studio-layout{grid-template-columns:1fr}.version-panel{position:static}.studio-version-list{max-height:500px}}@media(max-width:700px){.studio-toolbar,.studio-save-bar{align-items:stretch;flex-direction:column}.image-editor-grid,.itinerary-card-grid{grid-template-columns:1fr}.itinerary-card-grid .full{grid-column:auto}.gallery-preview-grid{grid-template-columns:repeat(2,1fr)}.studio-save-button{width:100%;min-width:0}}
.pagination{margin-top:15px;display:flex;gap:6px}.page-btn{min-width:35px;height:35px;border:1px solid var(--border);border-radius:7px;display:grid;place-items:center;background:#fff}.page-btn.active{background:var(--blue);border-color:var(--blue);color:#fff}
.setup{padding:35px;text-align:center}.setup h2{margin-top:12px}.setup p{margin:8px auto;max-width:650px;color:var(--muted)}
@media(max-width:1200px){.stats{grid-template-columns:repeat(3,1fr)}.details-grid{grid-template-columns:1fr}.activity-layout{grid-template-columns:1fr}.info-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:780px){.topbar{padding:12px 15px;align-items:flex-start;flex-direction:column}.page-head{align-items:flex-start;flex-direction:column}.stats{grid-template-columns:repeat(2,1fr)}.filters,.form-grid,.two-fields,.info-grid{grid-template-columns:1fr}.full{grid-column:auto}.page{width:94%}.nav a span{display:none}}

/* ========================================================================
   CRM QUOTATION IMAGE CHOOSER — SAME SOURCES AS ADMIN PACKAGES
   ======================================================================== */
.crm-image-choice-row{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:8px;
    margin:8px 0 10px;
}
.crm-image-choice{
    min-height:42px;
    padding:8px 10px;
    border:1px solid var(--border);
    border-radius:9px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    font-weight:800;
    font-size:11px;
    cursor:pointer;
}
.crm-choice-pc{background:#eef5ff;border-color:#c7dcff;color:#125fad}
.crm-choice-web{background:#f3efff;border-color:#d9cdfd;color:#6948be}
.crm-choice-free{background:#eafaf2;border-color:#bee8d2;color:#087952}
.crm-image-choice:hover{transform:translateY(-1px);box-shadow:0 5px 15px rgba(7,25,45,.08)}
.crm-gallery-help{margin-top:8px;color:var(--muted);font-size:10px;line-height:1.5}

.crm-media-modal{
    position:fixed;
    inset:0;
    z-index:5000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:rgba(3,15,29,.78);
}
.crm-media-modal.open{display:flex}
.crm-media-dialog{
    width:min(1050px,96vw);
    max-height:92vh;
    overflow:auto;
    border-radius:16px;
    background:#fff;
    box-shadow:0 25px 80px rgba(0,0,0,.28);
}
.crm-media-head{
    position:sticky;
    top:0;
    z-index:2;
    padding:15px 17px;
    border-bottom:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    background:#fff;
}
.crm-media-head h3{font:800 18px 'Manrope',sans-serif;color:var(--navy)}
.crm-media-head-actions{display:flex;align-items:center;gap:8px}
.crm-media-item.selected{border-color:#1aa36f;box-shadow:0 0 0 3px rgba(26,163,111,.12)}
.crm-media-item.selected .crm-media-select{background:#1aa36f}
.crm-media-item.selected .crm-media-select::before{content:'✓ ';}
.crm-media-close{
    width:38px;height:38px;border:0;border-radius:9px;
    background:#edf2f7;color:#526175;cursor:pointer
}
.crm-media-tools{
    padding:14px 17px;
    display:grid;
    grid-template-columns:1fr auto;
    gap:9px;
    border-bottom:1px solid var(--border);
    background:#f8fafc;
}
.crm-media-grid{
    padding:17px;
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
}
.crm-media-item{
    overflow:hidden;
    border:1px solid var(--border);
    border-radius:11px;
    background:#fff;
}
.crm-media-item img{width:100%;height:145px;object-fit:cover;display:block}
.crm-media-item-body{padding:9px}
.crm-media-item-name{
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
    color:var(--navy);
    font-size:11px;
    font-weight:800;
    margin-bottom:7px;
}
.crm-media-select{
    width:100%;
    min-height:34px;
    border:0;
    border-radius:7px;
    background:var(--blue);
    color:#fff;
    font-size:10px;
    font-weight:800;
    cursor:pointer;
}
.crm-free-credit{margin:-2px 0 7px;color:var(--muted);font-size:9px;display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.crm-provider-badge{display:inline-flex;align-items:center;padding:3px 6px;border-radius:999px;font-size:8px;font-weight:900;letter-spacing:.03em;text-transform:uppercase}
.crm-provider-badge.pexels{background:#e9fbf4;color:#047857;border:1px solid #b7ead6}
.crm-provider-badge.unsplash{background:#f1f5f9;color:#0f172a;border:1px solid #cbd5e1}
.crm-free-search-meta{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:10px 12px;border:1px solid #dbe7f3;border-radius:11px;background:#f8fbff}
.crm-free-search-counts{display:flex;gap:7px;flex-wrap:wrap}
.crm-free-filter{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:999px;padding:6px 10px;font-size:9px;font-weight:900;cursor:pointer}
.crm-free-filter.active{background:#0b74ff;border-color:#0b74ff;color:#fff}
.crm-free-provider-note{font-size:10px;color:#64748b}
.crm-free-provider-note.warn{color:#9a3412}
.crm-search-status{grid-column:1/-1;padding:35px;text-align:center;color:var(--muted)}
@media(max-width:850px){
    .crm-media-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .crm-image-choice-row{grid-template-columns:1fr}
}
@media(max-width:520px){
    .crm-media-grid{grid-template-columns:1fr}
    .crm-media-tools{grid-template-columns:1fr}
}


/* ========================================================================
   QUOTATION CONVERSATION MODAL — ADMIN PAGE
   ======================================================================== */

.crm-reply-modal{
    position:fixed;
    inset:0;
    z-index:9000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:rgba(4,18,34,.78);
    backdrop-filter:blur(3px);
}

.crm-reply-modal.open{
    display:flex !important;
}

.crm-reply-dialog{
    width:min(820px,96vw);
    max-height:92vh;
    border-radius:18px;
    background:#fff;
    box-shadow:0 28px 90px rgba(0,0,0,.30);
}

.crm-conversation-dialog{
    height:min(850px,92vh);
    display:flex;
    flex-direction:column;
    overflow:hidden;
}

.crm-reply-head{
    flex:0 0 auto;
    padding:17px 19px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    border-bottom:1px solid var(--border);
    background:linear-gradient(135deg,#f7f4ff,#ffffff);
}

.crm-reply-head h3{
    color:var(--navy);
    font:800 18px 'Manrope',sans-serif;
}

.crm-reply-head p{
    margin-top:4px;
    color:var(--muted);
    font-size:11px;
}

.crm-reply-close{
    width:38px;
    height:38px;
    flex:0 0 38px;
    border:0;
    border-radius:9px;
    display:grid;
    place-items:center;
    background:#edf2f7;
    color:#526175;
    cursor:pointer;
}

.crm-conversation-customer{
    flex:0 0 auto;
    padding:12px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    border-bottom:1px solid var(--border);
    background:#f8fafc;
}

.crm-conversation-customer strong{
    display:block;
    color:var(--navy);
    font-size:13px;
}

.crm-conversation-customer span{
    display:block;
    margin-top:2px;
    color:var(--muted);
    font-size:10px;
}

.crm-conversation-status{
    flex:0 0 auto;
    background:#fff8e8;
    color:#93650b;
    font-size:10px;
    line-height:1.5;
}

.crm-conversation-status:not(:empty){
    padding:8px 18px;
    border-bottom:1px solid #f0dfb5;
}

.crm-conversation-thread{
    flex:1 1 auto;
    min-height:260px;
    overflow-y:auto;
    padding:20px 18px;
    background:
        radial-gradient(circle at top right,rgba(11,116,255,.06),transparent 28%),
        #f3f6fa;
}

.crm-message-row{
    display:flex;
    margin-bottom:14px;
}

.crm-message-row.incoming{
    justify-content:flex-start;
}

.crm-message-row.outgoing{
    justify-content:flex-end;
}

.crm-message-bubble{
    width:min(82%,620px);
    padding:13px 15px;
    border-radius:15px;
    box-shadow:0 4px 14px rgba(7,25,45,.06);
}

.crm-message-row.incoming .crm-message-bubble{
    border:1px solid #d8e2ec;
    border-bottom-left-radius:5px;
    background:#fff;
}

.crm-message-row.outgoing .crm-message-bubble{
    border:1px solid #c7dcff;
    border-bottom-right-radius:5px;
    background:#eaf3ff;
}

.crm-message-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:5px;
}

.crm-message-top strong{
    color:var(--navy);
    font-size:11px;
}

.crm-message-top span{
    color:#8a96a6;
    font-size:9px;
}

.crm-message-subject{
    margin-bottom:7px;
    color:#4c5d72;
    font-size:10px;
    font-weight:800;
}

.crm-message-body{
    color:#425267;
    font-size:12px;
    line-height:1.65;
    overflow-wrap:anywhere;
}

.crm-conversation-compose{
    flex:0 0 auto;
    padding:14px 18px 18px;
    border-top:1px solid var(--border);
    background:#fff;
}

.crm-compose-label{
    margin-bottom:7px;
    color:var(--navy);
    font-size:11px;
    font-weight:800;
}

.crm-conversation-compose .crm-reply-text{
    width:100%;
    min-height:100px;
    padding:11px 12px;
    border:1px solid var(--border);
    border-radius:10px;
    resize:vertical;
    outline:none;
}

.crm-conversation-compose .crm-reply-text:focus{
    border-color:#8c79e6;
    box-shadow:0 0 0 3px rgba(110,85,213,.08);
}

.crm-reply-actions{
    margin-top:10px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.crm-reply-send{
    background:#6e55d5;
}

.crm-reply-whatsapp{
    border:1px solid #bde7d2;
    background:#eafaf2;
    color:#087952;
}

.version-actions .quotation-reply-action{
    border-color:#cfc8ff;
    background:#f3efff;
    color:#5e45bd;
    cursor:pointer;
}

.version-actions .quotation-reply-action:hover{
    border-color:#6e55d5;
    background:#6e55d5;
    color:#fff;
}

.crm-conversation-empty,
.crm-conversation-loading,
.crm-conversation-error{
    min-height:220px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:24px;
    text-align:center;
    color:var(--muted);
}

.crm-conversation-empty i,
.crm-conversation-loading i,
.crm-conversation-error i{
    font-size:24px;
    color:var(--blue);
}

.crm-conversation-empty strong{
    color:var(--navy);
    font-size:13px;
}

.crm-conversation-empty span{
    max-width:430px;
    font-size:11px;
    line-height:1.6;
}

.crm-conversation-error{
    color:#b7394b;
}

body.crm-conversation-open{
    overflow:hidden;
}

@media(max-width:650px){
    .crm-reply-modal{
        padding:10px;
    }

    .crm-conversation-dialog{
        width:100%;
        height:96vh;
        max-height:96vh;
        border-radius:12px;
    }

    .crm-message-bubble{
        width:92%;
    }

    .crm-conversation-customer{
        align-items:flex-start;
        flex-direction:column;
    }
}



/* ==========================================================================
   ADMIN CRM VISUAL SKIN
   Exact colour / typography / sizing system adapted from the first PHP.
   Presentation only: CRM logic, actions, forms and data remain unchanged.
   ========================================================================== */

:root{
    --forest:#183b32;
    --forest-dark:#0f2923;
    --gold:#b89452;
    --cream:#f5f1e9;
    --paper:#ffffff;
    --text:#24332e;
    --muted:#75807a;
    --border:#e7e2d9;
    --soft:#faf8f3;

    /* Map existing CRM variables onto the reference design palette */
    --navy:var(--forest);
    --blue:var(--gold);
    --cyan:#c9ad74;
    --bg:var(--cream);
    --white:var(--paper);
    --green:#3f715f;
    --orange:#b77b32;
    --red:#a94a4a;
    --purple:#7f6b50;
    --shadow:0 10px 30px rgba(24,59,50,.07);
}

html,
body{
    min-height:100%;
}

body{
    margin:0;
    padding:0;
    background:var(--cream);
    color:var(--text);
    font-family:Arial,Helvetica,sans-serif;
    font-size:14px;
    line-height:1.55;
}

/* Reference-style fixed sidebar layout */
.odisha-style-admin-layout{
    min-height:100vh;
    display:flex;
}

.odisha-style-sidebar{
    width:250px;
    padding:28px 18px;
    position:fixed;
    top:0;
    bottom:0;
    left:0;
    z-index:1200;
    overflow-y:auto;
    background:var(--forest);
    color:#fff;
}

.odisha-style-logo{
    padding:5px 12px 30px;
    margin-bottom:25px;
    border-bottom:1px solid rgba(255,255,255,.12);
}

.odisha-style-logo-name{
    color:#fff;
    font-family:Georgia,"Times New Roman",serif;
    font-size:28px;
    line-height:1;
    font-weight:400;
}

.odisha-style-logo-subtitle{
    margin-top:8px;
    color:rgba(255,255,255,.62);
    font-size:9px;
    letter-spacing:1.4px;
    text-transform:uppercase;
}

.odisha-style-menu-label{
    padding:0 12px;
    margin:22px 0 8px;
    color:rgba(255,255,255,.42);
    font-size:10px;
    letter-spacing:1.4px;
    text-transform:uppercase;
}

.odisha-style-nav-link{
    display:flex;
    align-items:center;
    gap:11px;
    padding:11px 12px;
    margin-bottom:3px;
    border-radius:7px;
    color:rgba(255,255,255,.78);
    text-decoration:none;
    font-size:13px;
    transition:background .2s ease,color .2s ease;
}

.odisha-style-nav-link:hover{
    background:rgba(255,255,255,.09);
    color:#fff;
}

.odisha-style-nav-link.active{
    background:rgba(255,255,255,.13);
    color:#fff;
}

.odisha-style-nav-icon{
    width:18px;
    text-align:center;
    opacity:.85;
}

.odisha-style-main{
    width:calc(100% - 250px);
    min-height:100vh;
    margin-left:250px;
}

/* Reference-style white top bar */
.topbar{
    min-height:78px;
    height:78px;
    padding:0 36px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    background:var(--paper);
    color:var(--text);
    border-bottom:1px solid var(--border);
}

.odisha-style-topbar-title{
    color:var(--muted);
    font-size:14px;
}

.odisha-style-topbar-right{
    color:var(--gold);
    font-size:10px;
    font-weight:700;
    letter-spacing:1px;
    text-transform:uppercase;
}

/* Main content width/padding from reference PHP */
.page{
    width:auto;
    max-width:none;
    margin:0;
    padding:38px;
}

/* Headings */
.eyebrow{
    margin-bottom:8px;
    color:var(--gold);
    font-size:10px;
    font-weight:700;
    letter-spacing:1.8px;
    text-transform:uppercase;
}

.page-head{
    margin-bottom:34px;
}

.page-head h1{
    margin:0;
    margin-top:0;
    color:var(--forest);
    font-family:Georgia,"Times New Roman",serif;
    font-size:34px;
    line-height:1.2;
    font-weight:500;
}

.page-head p{
    margin:10px 0 0;
    color:var(--muted);
    font-size:14px;
}

/* Buttons: reference border radius / sizing, CRM semantics preserved */
.button{
    min-height:38px;
    padding:9px 14px;
    border:1px solid var(--border);
    border-radius:6px;
    background:var(--forest);
    color:#fff;
    font-size:12px;
    font-weight:700;
    box-shadow:none;
}

.button.blue{
    background:var(--forest);
    border-color:var(--forest);
}

.button.green{
    background:var(--gold);
    border-color:var(--gold);
    color:#fff;
}

.button.light{
    background:var(--paper);
    border-color:var(--border);
    color:var(--text);
}

.button:hover{
    filter:none;
    border-color:var(--gold);
}

/* Alerts */
.alert{
    padding:13px 15px;
    border:1px solid var(--border);
    border-radius:7px;
    font-size:12px;
}

.alert-success{
    background:#f0f6f2;
    color:#386652;
}

.alert-error{
    background:#fbf0ef;
    color:#934b45;
}

/* Statistics exactly follow reference card language */
.stats{
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:17px;
    margin-bottom:32px;
}

.stat{
    padding:22px;
    border:1px solid var(--border);
    border-radius:10px;
    background:var(--paper);
    box-shadow:none;
    transition:transform .2s ease,box-shadow .2s ease;
}

.stat:hover{
    transform:translateY(-2px);
    box-shadow:0 10px 30px rgba(24,59,50,.07);
}

.stat span{
    color:var(--muted);
    font-size:11px;
    font-weight:400;
    letter-spacing:1px;
    text-transform:uppercase;
}

.stat strong{
    margin-top:10px;
    color:var(--forest);
    font-family:Georgia,"Times New Roman",serif;
    font-size:30px;
    line-height:1.1;
    font-weight:400;
}

/* Cards/panels */
.card,
.pipe-column,
.lead-card,
.source-box,
.activity,
.version-card,
.studio-section,
.version-panel,
.studio-version-card,
.crm-media-dialog,
.crm-reply-dialog,
.modal-box{
    border-color:var(--border);
    background:var(--paper);
    box-shadow:none;
}

.card{
    border-radius:10px;
}

.card-head{
    padding:21px 23px;
    border-bottom:1px solid var(--border);
}

.card-head h2{
    color:var(--forest);
    font-family:Georgia,"Times New Roman",serif;
    font-size:19px;
    font-weight:500;
}

.card-body{
    padding:23px;
}

/* Controls */
.control{
    min-height:41px;
    padding:0 11px;
    border:1px solid var(--border);
    border-radius:6px;
    background:var(--paper);
    color:var(--text);
    font-size:12px;
}

textarea.control{
    min-height:105px;
    padding:10px 11px;
}

.control:focus{
    border-color:var(--gold);
    box-shadow:0 0 0 3px rgba(184,148,82,.10);
}

.field label{
    margin-bottom:6px;
    color:var(--text);
    font-size:11px;
    font-weight:700;
}

/* Filters/tabs */
.filters{
    gap:10px;
    margin-bottom:18px;
}

.view-tabs{
    gap:7px;
    margin-bottom:18px;
}

.view-tabs a{
    padding:9px 12px;
    border:1px solid var(--border);
    border-radius:6px;
    background:var(--paper);
    color:var(--muted);
    font-size:12px;
    font-weight:700;
}

.view-tabs a.active{
    border-color:var(--gold);
    background:rgba(184,148,82,.10);
    color:var(--forest);
}

/* Tables */
table{
    color:var(--text);
}

th{
    padding:11px 10px;
    background:var(--soft);
    color:var(--muted);
    font-size:10px;
    font-weight:700;
    letter-spacing:1px;
}

td{
    padding:12px 10px;
    border-top:1px solid var(--border);
    font-size:12px;
}

.strong{
    color:var(--forest);
    font-weight:700;
}

.small{
    color:var(--muted);
    font-size:10px;
}

/* Common compact actions */
.action{
    min-height:32px;
    padding:0 9px;
    border:1px solid var(--border);
    border-radius:6px;
    background:var(--paper);
    color:var(--text);
    font-size:11px;
    font-weight:700;
}

.action:hover{
    border-color:var(--gold);
}

.action.manage{
    background:var(--forest);
    border-color:var(--forest);
    color:#fff;
}

/* Source overview */
.source-box{
    padding:14px;
    border-radius:8px;
}

.source-box strong{
    color:var(--forest);
    font-family:Georgia,"Times New Roman",serif;
    font-size:20px;
    font-weight:500;
}

.source-box small{
    color:var(--muted);
    font-size:10px;
}

/* Pipeline */
.pipe-column{
    border-radius:8px;
    background:var(--soft);
}

.pipe-head{
    border-bottom:1px solid var(--border);
    color:var(--forest);
    font-size:12px;
}

.lead-card{
    border-radius:7px;
}

.lead-card h3{
    color:var(--forest);
    font-size:13px;
}

.lead-card p{
    color:var(--muted);
    font-size:11px;
}

/* Details / info */
.info{
    border-color:var(--border);
    border-radius:7px;
    background:var(--soft);
}

.info label{
    color:var(--muted);
    font-size:10px;
}

.info div{
    color:var(--text);
    font-size:12px;
}

.contact{
    border-radius:6px;
    background:rgba(184,148,82,.10);
    color:var(--forest);
    font-size:11px;
}

.contact.quotation{
    border-color:rgba(184,148,82,.35);
    background:rgba(184,148,82,.12);
    color:#87692f;
}

/* Timeline */
.timeline{
    scrollbar-color:#c8b78f #f3eee3;
}

.activity{
    border-radius:8px;
    background:var(--soft);
}

.activity h3{
    color:var(--forest);
    font-size:13px;
}

.activity-meta{
    color:var(--muted);
    font-size:10px;
}

.activity p{
    color:var(--text);
    font-size:12px;
}

/* Modal heads */
.modal-head,
.crm-media-head,
.crm-reply-head{
    border-bottom-color:var(--border);
    background:var(--paper);
}

.modal-head h2,
.crm-media-head h3,
.crm-reply-head h3{
    color:var(--forest);
    font-family:Georgia,"Times New Roman",serif;
    font-size:19px;
    font-weight:500;
}

/* Package Studio */
.package-studio-card{
    border-radius:10px;
}

.studio-head{
    background:var(--paper);
}

.studio-eyebrow,
.version-number,
.version-panel-head span{
    color:var(--gold);
}

.studio-head p,
.version-meta,
.version-date,
.version-facts,
.snapshot-summary p,
.studio-save-bar span{
    color:var(--muted);
}

.safe-badge{
    background:rgba(63,113,95,.10);
    color:#3f715f;
}

.studio-toolbar,
.version-panel,
.crm-media-tools,
.crm-conversation-customer{
    background:var(--soft);
}

.studio-section{
    border-radius:8px;
}

.studio-section>summary{
    background:var(--soft);
    color:var(--forest);
    font-size:12px;
}

.studio-section>summary i{
    background:rgba(184,148,82,.11);
    color:var(--gold);
}

.studio-save-bar{
    border-color:var(--border);
    border-radius:8px;
    background:rgba(255,255,255,.97);
    box-shadow:0 10px 30px rgba(24,59,50,.09);
}

.version-panel{
    border-radius:8px;
}

.version-panel-head h3,
.studio-version-card h4{
    color:var(--forest);
    font-family:Georgia,"Times New Roman",serif;
    font-weight:500;
}

.version-price{
    color:var(--forest);
    font-family:Georgia,"Times New Roman",serif;
    font-weight:500;
}

/* Image chooser adopts same palette */
.crm-image-choice{
    border-color:var(--border);
    border-radius:7px;
    background:var(--soft);
    color:var(--forest);
    font-size:11px;
}

.crm-choice-pc,
.crm-choice-web,
.crm-choice-free{
    border-color:var(--border);
    background:var(--soft);
    color:var(--forest);
}

.crm-image-choice:hover{
    border-color:var(--gold);
    box-shadow:0 5px 15px rgba(24,59,50,.06);
}

.crm-media-close,
.crm-reply-close,
.modal-close{
    background:var(--soft);
    color:var(--forest);
}

.crm-media-select{
    background:var(--forest);
}

/* Conversation */
.crm-reply-head p,
.crm-conversation-customer span,
.crm-message-top span,
.crm-message-subject,
.crm-conversation-empty span{
    color:var(--muted);
}

.crm-conversation-thread{
    background:var(--cream);
}

.crm-message-row.outgoing .crm-message-bubble{
    border-color:#d9cfba;
    background:#f8f3e8;
}

.crm-message-row.incoming .crm-message-bubble{
    border-color:var(--border);
    background:var(--paper);
}

.crm-message-top strong,
.crm-conversation-customer strong,
.crm-conversation-empty strong{
    color:var(--forest);
}

.crm-reply-send{
    background:var(--forest);
}

.crm-reply-whatsapp{
    color:#3f715f;
}

/* Badges keep meaning, but soften to reference design */
.badge{
    border-radius:20px;
    font-size:10px;
}

.status-new,
.priority-medium{
    background:#f1eee7;
    color:#6d624c;
}

.status-contacted{
    background:#f3efe8;
    color:#7b6846;
}

.status-qualified,
.status-converted,
.priority-low{
    background:#edf4ef;
    color:#48705f;
}

.status-follow_up,
.priority-high{
    background:#f7f0df;
    color:#8b6729;
}

.status-proposal{
    background:#f5ebe0;
    color:#8a5d32;
}

.status-closed{
    background:#f0f0ed;
    color:#6f7772;
}

.priority-urgent{
    background:#f8eaea;
    color:#944d4d;
}

/* Pagination */
.pagination{
    margin-top:18px;
}

.page-btn{
    border-color:var(--border);
    border-radius:6px;
    background:var(--paper);
    color:var(--text);
    font-size:11px;
}

.page-btn.active{
    background:var(--forest);
    border-color:var(--forest);
    color:#fff;
}

/* Setup/empty */
.setup{
    color:var(--text);
}

.setup h2{
    color:var(--forest);
    font-family:Georgia,"Times New Roman",serif;
    font-weight:500;
}

.setup p{
    color:var(--muted);
}

/* Responsive behaviour follows reference PHP */
@media(max-width:1100px){
    .stats{
        grid-template-columns:repeat(2,1fr);
    }
}

@media(max-width:850px){
    .odisha-style-sidebar{
        width:210px;
    }

    .odisha-style-main{
        width:calc(100% - 210px);
        margin-left:210px;
    }

    .page{
        padding:30px 24px;
    }
}

@media(max-width:650px){
    .odisha-style-sidebar{
        display:none;
    }

    .odisha-style-main{
        width:100%;
        margin-left:0;
    }

    .topbar{
        height:auto;
        min-height:68px;
        padding:14px 18px;
    }

    .odisha-style-topbar-right{
        display:none;
    }

    .page{
        padding:24px 18px;
    }

    .page-head{
        margin-bottom:24px;
    }

    .page-head h1{
        font-size:28px;
    }

    .stats{
        grid-template-columns:1fr;
    }

    .stat{
        padding:18px;
    }

    .filters,
    .form-grid,
    .two-fields,
    .info-grid{
        grid-template-columns:1fr;
    }
}

/* Do not display the old horizontal CRM navigation; its exact destinations
   are preserved in the new reference-style sidebar above. */
.brand,
.nav{
    display:none !important;
}



/* ==========================================================================
   MODERN CRM WORKSPACE — ONE SCREEN / SPACE MANAGEMENT
   Presentation only. No CRM PHP/database/form/action logic is changed.
   ========================================================================== */

html,
body{
    height:100%;
}

body{
    overflow:hidden;
}

/* ---------- APPLICATION FRAME ---------- */

.odisha-style-admin-layout{
    height:100vh;
    min-height:100vh;
    overflow:hidden;
}

.odisha-style-sidebar{
    width:218px;
    padding:18px 13px;
}

.odisha-style-logo{
    padding:3px 9px 18px;
    margin-bottom:13px;
}

.odisha-style-logo-name{
    font-size:23px;
}

.odisha-style-logo-subtitle{
    margin-top:6px;
    font-size:10px;
}

.odisha-style-menu-label{
    margin:13px 0 5px;
    padding:0 9px;
    font-size:10px;
}

.odisha-style-nav-link{
    min-height:37px;
    padding:8px 9px;
    gap:9px;
    font-size:12px;
}

.odisha-style-main{
    width:calc(100% - 218px);
    height:100vh;
    min-height:0;
    margin-left:218px;
    overflow:hidden;
}

.topbar{
    height:58px;
    min-height:58px;
    padding:0 20px;
}

.page{
    width:100%;
    max-width:none;
    height:calc(100vh - 58px);
    min-height:0;
    margin:0;
    padding:14px 18px 18px;
    overflow:auto;
    overscroll-behavior:contain;
}

/* ---------- PAGE HEADER ---------- */

.page-head{
    margin-bottom:11px;
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:14px;
    align-items:end;
}

.eyebrow{
    margin-bottom:4px;
    font-size:10px;
}

.page-head h1{
    font-size:27px;
    line-height:1.1;
}

.page-head p{
    margin-top:4px;
    font-size:12px;
}

.page-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:7px;
    flex-wrap:wrap;
}

.button{
    min-height:35px;
    padding:7px 11px;
    font-size:11px;
}

/* ---------- KPI CARDS ---------- */

.stats{
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:9px;
    margin-bottom:10px;
}

.stat{
    min-height:72px;
    padding:11px 13px;
    border-radius:8px;
}

.stat span{
    font-size:10px;
}

.stat strong{
    margin-top:4px;
    font-size:22px;
}

/* ---------- SOURCE SUMMARY ---------- */

.source-summary{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:9px;
    margin-bottom:10px;
}

.source-box{
    min-height:68px;
    padding:10px 12px;
}

.source-box strong{
    font-size:18px;
}

/* ---------- SELECTED LEAD WORKSPACE ---------- */

.crm-workspace-tabs{
    position:sticky;
    top:-14px;
    z-index:160;
    display:flex;
    align-items:center;
    gap:6px;
    margin:0 0 9px;
    padding:7px;
    border:1px solid var(--border);
    border-radius:9px;
    background:rgba(255,255,255,.97);
    box-shadow:0 5px 16px rgba(24,59,50,.06);
    backdrop-filter:blur(8px);
}

.crm-workspace-tab{
    min-height:34px;
    padding:0 11px;
    border:1px solid transparent;
    border-radius:6px;
    background:transparent;
    color:var(--muted);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    font-family:Arial,Helvetica,sans-serif;
    font-size:11px;
    font-weight:700;
    cursor:pointer;
    white-space:nowrap;
}

.crm-workspace-tab:hover{
    background:var(--soft);
    color:var(--forest);
}

.crm-workspace-tab.active{
    border-color:rgba(184,148,82,.32);
    background:rgba(184,148,82,.12);
    color:var(--forest);
}

.crm-workspace-panel{
    display:none !important;
    margin:0 !important;
}

.crm-workspace-panel.is-active{
    display:grid !important;
}

/* Overview panel keeps Customer + Lead Management side by side */
.details-grid.crm-workspace-panel.is-active{
    grid-template-columns:minmax(0,1.08fr) minmax(340px,.92fr);
    gap:10px;
    align-items:start;
}

.details-grid .card{
    margin:0;
}

/* Package/activity are single cards */
.package-studio-card.crm-workspace-panel.is-active,
#activities.crm-workspace-panel.is-active{
    display:block !important;
}

/* ---------- CARD DENSITY ---------- */

.card{
    border-radius:9px;
}

.card-head{
    min-height:48px;
    padding:11px 14px;
}

.card-head h2{
    font-size:16px;
}

.card-body{
    padding:13px 14px;
}

.info-grid{
    gap:8px;
}

.info{
    min-height:57px;
    padding:9px 10px;
    border-radius:6px;
}

.info label{
    font-size:10px;
}

.info div{
    margin-top:2px;
    font-size:11px;
}

.contact-row{
    margin-top:10px;
    gap:6px;
}

.contact{
    min-height:31px;
    padding:0 9px;
    font-size:10px;
}

/* Lead management fields */
.two-fields,
.form-grid{
    gap:9px 11px;
}

.field{
    margin-bottom:9px;
}

.field label{
    margin-bottom:4px;
    font-size:10px;
}

.control{
    min-height:36px;
    padding:0 9px;
    font-size:11px;
}

textarea.control{
    min-height:65px;
    padding:8px 9px;
}

/* ---------- PACKAGE STUDIO ---------- */

.package-studio-card{
    min-height:0 !important;
}

.studio-head{
    padding:12px 14px;
}

.studio-head h2{
    font-size:18px;
}

.studio-head p{
    margin-top:3px;
    font-size:10px;
}

.safe-badge{
    font-size:10px;
}

.package-studio-card .card-body{
    padding:12px;
}

.studio-layout{
    gap:10px;
}

.studio-toolbar{
    padding:9px 10px;
    margin-bottom:9px;
}

.studio-section{
    margin-bottom:7px;
    border-radius:7px;
}

.studio-section>summary{
    min-height:40px;
    padding:8px 10px;
    font-size:11px;
}

.studio-section-body{
    padding:10px;
}

/* No artificial blank heights */
.package-studio-card,
.package-studio-card .card-body,
.package-studio-card .studio-layout,
.package-studio-card .studio-editor,
.package-studio-card .version-panel,
.package-studio-card .studio-section,
#activities,
.activity-layout{
    min-height:0 !important;
    height:auto;
}

/* Keep package editor inside one screen via internal scrolling */
.package-studio-card.crm-workspace-panel.is-active{
    max-height:calc(100vh - 235px);
    overflow:auto;
    scrollbar-width:thin;
}

/* Compact image picker */
.crm-image-choice-row{
    gap:6px;
}

.crm-image-choice{
    min-height:56px;
    padding:7px 6px;
    font-size:10px;
}

.main-image-preview{
    min-height:120px !important;
    max-height:180px;
}

.main-image-preview img{
    max-height:180px;
    object-fit:cover;
}

/* Pricing/itinerary never force the page wide or create print-like blanks */
.price-table-wrap,
.table-wrap{
    max-width:100%;
    overflow:auto;
}

/* ---------- ACTIVITIES ---------- */

#activities.crm-workspace-panel.is-active{
    max-height:calc(100vh - 235px);
    overflow:auto;
}

.activity-layout{
    grid-template-columns:minmax(280px,.75fr) minmax(0,1.25fr);
    gap:10px;
}

.timeline{
    max-height:calc(100vh - 310px);
    overflow:auto;
}

.activity{
    padding:10px;
    border-radius:7px;
}

/* ---------- FILTERS + LEAD LIST ---------- */

.filters{
    margin-top:11px;
    margin-bottom:8px;
    gap:7px;
}

.view-tabs{
    margin-bottom:8px;
    gap:6px;
}

.view-tabs a{
    padding:7px 9px;
    font-size:10px;
}

.table-wrap{
    max-height:42vh;
    overflow:auto;
}

th{
    position:sticky;
    top:0;
    z-index:2;
}

th,
td{
    padding:8px 7px;
}

/* ---------- RESPONSIVE ---------- */

@media(max-width:1150px){
    .stats{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }

    .source-summary{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .details-grid.crm-workspace-panel.is-active{
        grid-template-columns:1fr;
    }
}

@media(max-width:850px){
    body{
        overflow:auto;
    }

    .odisha-style-admin-layout{
        height:auto;
        min-height:100vh;
        overflow:visible;
    }

    .odisha-style-sidebar{
        display:none;
    }

    .odisha-style-main{
        width:100%;
        height:auto;
        margin-left:0;
        overflow:visible;
    }

    .page{
        height:auto;
        min-height:calc(100vh - 58px);
        padding:12px;
        overflow:visible;
    }

    .crm-workspace-tabs{
        top:0;
        overflow-x:auto;
    }

    .package-studio-card.crm-workspace-panel.is-active,
    #activities.crm-workspace-panel.is-active,
    .timeline,
    .table-wrap{
        max-height:none;
        overflow:visible;
    }

    .activity-layout{
        grid-template-columns:1fr;
    }
}

@media(max-width:650px){
    .page-head{
        grid-template-columns:1fr;
        gap:8px;
    }

    .page-head h1{
        font-size:23px;
    }

    .stats{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .source-summary{
        grid-template-columns:1fr;
    }

    .crm-workspace-tabs{
        padding:5px;
        gap:4px;
    }

    .crm-workspace-tab{
        min-height:32px;
        padding:0 8px;
        font-size:10px;
    }

    .card-head,
    .card-body{
        padding-left:10px;
        padding-right:10px;
    }
}



/* ==========================================================================
   TRAVSCOPE ADMIN — PROFESSIONAL INTERNATIONAL UI SYSTEM
   VISUAL / RESPONSIVE / FONT-SIZE UPGRADE ONLY
   Existing PHP, CRM actions, database logic, quotation, package studio,
   Pexels, email sync and settings remain unchanged.
   ========================================================================== */

:root{
    /* Core admin palette */
    --admin-navy:#0f172a;
    --admin-navy-2:#111c32;
    --admin-blue:#2563eb;
    --admin-blue-hover:#1d4ed8;
    --admin-blue-soft:#eff6ff;
    --admin-cyan:#0ea5e9;
    --admin-bg:#f4f7fb;
    --admin-surface:#ffffff;
    --admin-surface-soft:#f8fafc;
    --admin-text:#1e293b;
    --admin-muted:#64748b;
    --admin-border:#e2e8f0;
    --admin-border-strong:#cbd5e1;
    --admin-success:#15803d;
    --admin-success-soft:#ecfdf3;
    --admin-warning:#b45309;
    --admin-warning-soft:#fff7ed;
    --admin-danger:#b91c1c;
    --admin-danger-soft:#fef2f2;
    --admin-purple:#7c3aed;

    --admin-radius:10px;
    --admin-radius-sm:7px;
    --admin-shadow:0 6px 18px rgba(15,23,42,.055);
    --admin-shadow-hover:0 10px 26px rgba(15,23,42,.085);
    --admin-focus:0 0 0 3px rgba(37,99,235,.14);

    /* Map legacy CRM variables to the unified theme */
    --navy:var(--admin-navy);
    --blue:var(--admin-blue);
    --cyan:var(--admin-cyan);
    --bg:var(--admin-bg);
    --white:var(--admin-surface);
    --green:var(--admin-success);
    --orange:var(--admin-warning);
    --red:var(--admin-danger);
    --purple:var(--admin-purple);
    --border:var(--admin-border);
    --text:var(--admin-text);
    --muted:var(--admin-muted);
    --shadow:var(--admin-shadow);
}

html{
    -webkit-text-size-adjust:100%;
    text-rendering:optimizeLegibility;
}

html,
body{
    width:100%;
    min-height:100%;
}

body{
    margin:0;
    background:var(--admin-bg);
    color:var(--admin-text);
    font-family:"DM Sans",Arial,Helvetica,sans-serif;
    font-size:14px;
    line-height:1.5;
    overflow:hidden;
}

button,
input,
select,
textarea{
    font:inherit;
}

a,
button,
input,
select,
textarea{
    -webkit-tap-highlight-color:transparent;
}

a:focus-visible,
button:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible,
summary:focus-visible{
    outline:none;
    box-shadow:var(--admin-focus);
}

/* =========================
   ADMIN APPLICATION SHELL
========================= */

.odisha-style-admin-layout{
    width:100%;
    height:100vh;
    min-height:100vh;
    display:flex;
    overflow:hidden;
    background:var(--admin-bg);
}

.odisha-style-sidebar{
    width:224px;
    flex:0 0 224px;
    position:fixed;
    inset:0 auto 0 0;
    z-index:1200;
    padding:18px 12px 20px;
    overflow-y:auto;
    background:linear-gradient(180deg,var(--admin-navy) 0%,var(--admin-navy-2) 100%);
    color:#fff;
    border-right:1px solid rgba(255,255,255,.05);
    box-shadow:8px 0 28px rgba(15,23,42,.08);
    scrollbar-width:thin;
    scrollbar-color:#334155 transparent;
}

.odisha-style-logo{
    padding:4px 10px 18px;
    margin-bottom:12px;
    border-bottom:1px solid rgba(255,255,255,.08);
}

.odisha-style-logo-name{
    color:#fff;
    font-family:"Manrope","DM Sans",Arial,sans-serif;
    font-size:21px;
    line-height:1.1;
    font-weight:800;
    letter-spacing:-.5px;
}

.odisha-style-logo-subtitle{
    margin-top:5px;
    color:#94a3b8;
    font-size:10px;
    font-weight:700;
    letter-spacing:.9px;
    text-transform:uppercase;
}

.odisha-style-menu-label{
    padding:0 10px;
    margin:15px 0 6px;
    color:#64748b;
    font-size:10px;
    font-weight:800;
    letter-spacing:.9px;
    text-transform:uppercase;
}

.odisha-style-nav-link{
    min-height:40px;
    padding:0 10px;
    margin:3px 0;
    border-radius:8px;
    display:flex;
    align-items:center;
    gap:10px;
    color:#b7c4d5;
    font-size:12px;
    font-weight:700;
    text-decoration:none;
    transition:background .15s ease,color .15s ease,transform .15s ease;
}

.odisha-style-nav-link:hover{
    background:rgba(255,255,255,.07);
    color:#fff;
}

.odisha-style-nav-link.active{
    background:linear-gradient(135deg,var(--admin-blue),#3b82f6);
    color:#fff;
    box-shadow:0 5px 14px rgba(37,99,235,.23);
}

.odisha-style-nav-icon{
    width:18px;
    flex:0 0 18px;
    text-align:center;
    font-size:13px;
}

.odisha-style-main{
    width:calc(100% - 224px);
    height:100vh;
    min-height:0;
    margin-left:224px;
    overflow:hidden;
    background:var(--admin-bg);
}

/* =========================
   TOP BAR
========================= */

.topbar{
    height:62px;
    min-height:62px;
    padding:0 20px;
    position:sticky;
    top:0;
    z-index:1000;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    background:rgba(255,255,255,.96);
    border-bottom:1px solid var(--admin-border);
    box-shadow:0 1px 0 rgba(15,23,42,.02);
    backdrop-filter:blur(10px);
}

.odisha-style-topbar-title{
    color:var(--admin-text);
    font-family:"Manrope","DM Sans",Arial,sans-serif;
    font-size:14px;
    font-weight:800;
}

.odisha-style-topbar-right{
    color:var(--admin-muted);
    font-size:11px;
    font-weight:700;
}

/* =========================
   MAIN PAGE
========================= */

.page{
    width:100%;
    max-width:none;
    height:calc(100vh - 62px);
    min-height:0;
    margin:0;
    padding:16px 18px 20px;
    overflow:auto;
    overscroll-behavior:contain;
    scrollbar-width:thin;
    scrollbar-color:#cbd5e1 transparent;
}

.page-head{
    margin-bottom:13px;
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:16px;
}

.eyebrow{
    margin-bottom:4px;
    color:var(--admin-blue);
    font-size:10px;
    font-weight:800;
    letter-spacing:1.2px;
    text-transform:uppercase;
}

.page-head h1{
    margin:0;
    color:var(--admin-navy);
    font-family:"Manrope","DM Sans",Arial,sans-serif;
    font-size:27px;
    line-height:1.15;
    font-weight:800;
    letter-spacing:-.65px;
}

.page-head p{
    margin:4px 0 0;
    color:var(--admin-muted);
    font-size:12px;
    line-height:1.5;
}

.page-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:7px;
    flex-wrap:wrap;
}

/* =========================
   BUTTONS
========================= */

.button,
.action,
.page-btn,
.crm-media-select,
.crm-reply-send{
    min-height:36px;
    padding:0 11px;
    border:1px solid transparent;
    border-radius:7px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    font-size:11px;
    font-weight:800;
    line-height:1;
    cursor:pointer;
    text-decoration:none;
    transition:background .15s ease,border-color .15s ease,box-shadow .15s ease,transform .12s ease;
}

.button:hover,
.action:hover,
.page-btn:hover{
    transform:translateY(-1px);
}

.button.blue,
.action.manage,
.crm-media-select,
.crm-reply-send{
    background:var(--admin-blue);
    border-color:var(--admin-blue);
    color:#fff;
    box-shadow:0 4px 12px rgba(37,99,235,.15);
}

.button.blue:hover,
.action.manage:hover,
.crm-media-select:hover,
.crm-reply-send:hover{
    background:var(--admin-blue-hover);
    border-color:var(--admin-blue-hover);
}

.button.green{
    background:var(--admin-success);
    border-color:var(--admin-success);
    color:#fff;
}

.button.light,
.action,
.page-btn{
    background:#fff;
    border-color:var(--admin-border);
    color:#475569;
}

.button.light:hover,
.action:hover,
.page-btn:hover{
    border-color:#b9c6d6;
    background:#f8fafc;
}

/* =========================
   ALERTS
========================= */

.alert{
    margin-bottom:10px;
    padding:10px 12px;
    border:1px solid var(--admin-border);
    border-radius:8px;
    font-size:12px;
    font-weight:600;
}

.alert-success{
    background:var(--admin-success-soft);
    border-color:#bbf7d0;
    color:#166534;
}

.alert-error{
    background:var(--admin-danger-soft);
    border-color:#fecaca;
    color:#991b1b;
}

/* =========================
   KPI / STATISTICS
========================= */

.stats{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:9px;
    margin-bottom:11px;
}

.stat{
    min-width:0;
    min-height:76px;
    padding:12px 13px;
    border:1px solid var(--admin-border);
    border-radius:9px;
    background:#fff;
    box-shadow:0 3px 12px rgba(15,23,42,.035);
    transition:border-color .15s ease,box-shadow .15s ease,transform .15s ease;
}

.stat:hover{
    border-color:#cbd5e1;
    box-shadow:var(--admin-shadow-hover);
    transform:translateY(-1px);
}

.stat span{
    color:var(--admin-muted);
    font-size:10px;
    font-weight:800;
    letter-spacing:.45px;
    text-transform:uppercase;
}

.stat strong{
    display:block;
    margin-top:5px;
    color:var(--admin-navy);
    font-family:"Manrope","DM Sans",Arial,sans-serif;
    font-size:22px;
    line-height:1.05;
    font-weight:800;
    letter-spacing:-.4px;
}

/* =========================
   CARDS
========================= */

.card,
.pipe-column,
.lead-card,
.source-box,
.activity,
.version-card,
.version-panel,
.studio-version-card,
.crm-media-dialog,
.crm-reply-dialog,
.modal-box{
    border:1px solid var(--admin-border);
    background:#fff;
    box-shadow:var(--admin-shadow);
}

.card{
    margin-bottom:11px;
    border-radius:var(--admin-radius);
    overflow:hidden;
}

.card-head{
    min-height:50px;
    padding:11px 14px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    border-bottom:1px solid var(--admin-border);
    background:#fff;
}

.card-head h2,
.card-head h3{
    margin:0;
    color:var(--admin-navy);
    font-family:"Manrope","DM Sans",Arial,sans-serif;
    font-size:15px;
    line-height:1.3;
    font-weight:800;
    letter-spacing:-.2px;
}

.card-head p{
    margin:3px 0 0;
    color:var(--admin-muted);
    font-size:10px;
}

.card-body{
    padding:13px 14px;
}

/* =========================
   FORMS
========================= */

.field{
    margin-bottom:9px;
}

.field label{
    display:block;
    margin-bottom:4px;
    color:#334155;
    font-size:10px;
    font-weight:800;
    letter-spacing:.15px;
}

.control{
    width:100%;
    min-height:38px;
    padding:0 10px;
    border:1px solid var(--admin-border-strong);
    border-radius:7px;
    background:#fff;
    color:var(--admin-text);
    font-size:12px;
    outline:none;
    transition:border-color .15s ease,box-shadow .15s ease,background .15s ease;
}

textarea.control{
    min-height:76px;
    padding:9px 10px;
    line-height:1.5;
    resize:vertical;
}

.control::placeholder{
    color:#94a3b8;
}

.control:hover{
    border-color:#aebccd;
}

.control:focus{
    border-color:#60a5fa;
    box-shadow:var(--admin-focus);
}

.form-grid,
.two-fields{
    gap:9px 11px;
}

/* =========================
   FILTERS / TABS
========================= */

.filters{
    gap:7px;
    margin-bottom:9px;
}

.view-tabs{
    display:flex;
    gap:5px;
    margin-bottom:9px;
    overflow-x:auto;
    scrollbar-width:none;
}

.view-tabs a{
    min-height:34px;
    padding:0 10px;
    border:1px solid var(--admin-border);
    border-radius:7px;
    display:inline-flex;
    align-items:center;
    color:var(--admin-muted);
    background:#fff;
    font-size:10px;
    font-weight:800;
    white-space:nowrap;
}

.view-tabs a.active{
    border-color:#bfdbfe;
    background:var(--admin-blue-soft);
    color:var(--admin-blue);
}

/* =========================
   CRM WORKSPACE TABS
========================= */

.crm-workspace-tabs{
    position:sticky;
    top:-16px;
    z-index:180;
    display:flex;
    align-items:center;
    gap:5px;
    margin:0 0 9px;
    padding:6px;
    border:1px solid var(--admin-border);
    border-radius:9px;
    background:rgba(255,255,255,.97);
    box-shadow:0 5px 14px rgba(15,23,42,.05);
    backdrop-filter:blur(8px);
    overflow-x:auto;
}

.crm-workspace-tab{
    min-height:34px;
    padding:0 10px;
    border:1px solid transparent;
    border-radius:7px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    background:transparent;
    color:var(--admin-muted);
    font-size:11px;
    font-weight:800;
    cursor:pointer;
    white-space:nowrap;
}

.crm-workspace-tab:hover{
    background:#f8fafc;
    color:var(--admin-navy);
}

.crm-workspace-tab.active{
    background:var(--admin-blue-soft);
    border-color:#bfdbfe;
    color:var(--admin-blue);
}

/* =========================
   INFO / DETAILS
========================= */

.info{
    min-height:58px;
    padding:9px 10px;
    border:1px solid var(--admin-border);
    border-radius:7px;
    background:var(--admin-surface-soft);
}

.info label{
    color:var(--admin-muted);
    font-size:9px;
    font-weight:800;
    letter-spacing:.4px;
    text-transform:uppercase;
}

.info div{
    margin-top:3px;
    color:var(--admin-text);
    font-size:11px;
    font-weight:700;
}

.contact{
    min-height:31px;
    padding:0 9px;
    border-radius:6px;
    background:var(--admin-blue-soft);
    color:var(--admin-blue);
    font-size:10px;
    font-weight:800;
}

.contact.quotation{
    background:#f5f3ff;
    color:#6d28d9;
}

/* =========================
   TABLES
========================= */

.table-wrap,
.price-table-wrap{
    width:100%;
    max-width:100%;
    overflow:auto;
    border-radius:8px;
}

table{
    width:100%;
    border-collapse:collapse;
    color:var(--admin-text);
    font-size:11px;
}

th{
    padding:9px 8px;
    position:sticky;
    top:0;
    z-index:2;
    background:#f8fafc;
    color:#64748b;
    border-bottom:1px solid var(--admin-border);
    font-size:9px;
    font-weight:800;
    letter-spacing:.55px;
    text-align:left;
    text-transform:uppercase;
    white-space:nowrap;
}

td{
    padding:9px 8px;
    border-top:1px solid #eef2f6;
    font-size:11px;
    vertical-align:middle;
}

tbody tr:hover{
    background:#fbfdff;
}

.strong{
    color:var(--admin-navy);
    font-weight:800;
}

.small{
    color:var(--admin-muted);
    font-size:9px;
}

/* =========================
   BADGES / STATUS
========================= */

.badge{
    min-height:24px;
    padding:0 7px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:9px;
    font-weight:800;
    line-height:1;
}

.status-new,
.priority-medium{
    background:#f1f5f9;
    color:#475569;
}

.status-contacted{
    background:#eff6ff;
    color:#1d4ed8;
}

.status-qualified,
.status-converted,
.priority-low{
    background:#ecfdf3;
    color:#15803d;
}

.status-follow_up,
.priority-high{
    background:#fff7ed;
    color:#b45309;
}

.status-proposal{
    background:#f5f3ff;
    color:#7c3aed;
}

.status-closed{
    background:#f1f5f9;
    color:#64748b;
}

.priority-urgent{
    background:#fef2f2;
    color:#b91c1c;
}

/* =========================
   PIPELINE / LEADS
========================= */

.pipe-column{
    border-radius:9px;
    background:#f8fafc;
    box-shadow:none;
}

.pipe-head{
    padding:10px 11px;
    border-bottom:1px solid var(--admin-border);
    color:var(--admin-navy);
    font-size:11px;
    font-weight:800;
}

.lead-card{
    border-radius:8px;
    box-shadow:none;
    transition:border-color .15s ease,box-shadow .15s ease;
}

.lead-card:hover{
    border-color:#bfdbfe;
    box-shadow:0 5px 14px rgba(15,23,42,.055);
}

.lead-card h3{
    color:var(--admin-navy);
    font-size:12px;
    font-weight:800;
}

.lead-card p{
    color:var(--admin-muted);
    font-size:10px;
}

/* =========================
   SOURCE BOXES
========================= */

.source-summary{
    gap:8px;
    margin-bottom:10px;
}

.source-box{
    min-height:68px;
    padding:10px 11px;
    border-radius:8px;
    box-shadow:none;
}

.source-box strong{
    color:var(--admin-navy);
    font-family:"Manrope","DM Sans",Arial,sans-serif;
    font-size:17px;
    font-weight:800;
}

.source-box small{
    color:var(--admin-muted);
    font-size:9px;
}

/* =========================
   PACKAGE STUDIO
========================= */

.package-studio-card{
    border-radius:10px;
}

.studio-head{
    padding:12px 14px;
    background:#fff;
    border-bottom:1px solid var(--admin-border);
}

.studio-head h2{
    color:var(--admin-navy);
    font-family:"Manrope","DM Sans",Arial,sans-serif;
    font-size:18px;
    font-weight:800;
    letter-spacing:-.25px;
}

.studio-head p{
    color:var(--admin-muted);
    font-size:10px;
}

.safe-badge{
    border-radius:999px;
    background:#ecfdf3;
    color:#15803d;
    font-size:9px;
    font-weight:800;
}

.studio-toolbar{
    padding:9px 10px;
    background:#f8fafc;
    border-bottom:1px solid var(--admin-border);
}

.studio-section{
    margin-bottom:7px;
    border:1px solid var(--admin-border);
    border-radius:8px;
    background:#fff;
    overflow:hidden;
}

.studio-section>summary{
    min-height:40px;
    padding:8px 10px;
    background:#f8fafc;
    color:var(--admin-navy);
    font-size:11px;
    font-weight:800;
    cursor:pointer;
}

.studio-section>summary i{
    color:var(--admin-blue);
}

.studio-section-body{
    padding:10px;
}

.version-panel,
.studio-version-card{
    border-radius:8px;
    box-shadow:none;
}

.version-panel-head h3,
.studio-version-card h4{
    color:var(--admin-navy);
    font-family:"Manrope","DM Sans",Arial,sans-serif;
    font-size:13px;
    font-weight:800;
}

.version-number{
    color:var(--admin-blue);
    font-size:10px;
    font-weight:800;
}

.version-meta,
.version-date,
.version-facts,
.snapshot-summary p,
.studio-save-bar span{
    color:var(--admin-muted);
    font-size:9px;
}

.version-price{
    color:var(--admin-navy);
    font-family:"Manrope","DM Sans",Arial,sans-serif;
    font-weight:800;
}

.studio-save-bar{
    border:1px solid var(--admin-border);
    border-radius:9px;
    background:rgba(255,255,255,.98);
    box-shadow:0 8px 22px rgba(15,23,42,.08);
}

/* =========================
   MEDIA / IMAGE PICKER
========================= */

.crm-image-choice{
    min-height:58px;
    padding:7px;
    border:1px solid var(--admin-border);
    border-radius:8px;
    background:#fff;
    color:var(--admin-text);
    font-size:10px;
    font-weight:700;
}

.crm-image-choice:hover{
    border-color:#93c5fd;
    box-shadow:0 4px 12px rgba(37,99,235,.08);
}

.crm-choice-pc,
.crm-choice-web,
.crm-choice-free{
    border-color:var(--admin-border);
    background:#f8fafc;
    color:var(--admin-navy);
}

.crm-media-dialog,
.crm-reply-dialog,
.modal-box{
    border-radius:11px;
}

.crm-media-head,
.crm-reply-head,
.modal-head{
    min-height:52px;
    padding:11px 14px;
    border-bottom:1px solid var(--admin-border);
    background:#fff;
}

.crm-media-head h3,
.crm-reply-head h3,
.modal-head h2{
    color:var(--admin-navy);
    font-family:"Manrope","DM Sans",Arial,sans-serif;
    font-size:15px;
    font-weight:800;
}

.crm-media-close,
.crm-reply-close,
.modal-close{
    background:#f8fafc;
    color:#475569;
}

/* =========================
   CONVERSATION / ACTIVITY
========================= */

.activity{
    padding:10px;
    border-radius:8px;
    box-shadow:none;
}

.activity h3{
    color:var(--admin-navy);
    font-size:12px;
}

.activity-meta{
    color:var(--admin-muted);
    font-size:9px;
}

.activity p{
    color:var(--admin-text);
    font-size:11px;
}

.crm-conversation-thread{
    background:#f8fafc;
}

.crm-message-row.outgoing .crm-message-bubble{
    background:#eff6ff;
    border-color:#bfdbfe;
}

.crm-message-row.incoming .crm-message-bubble{
    background:#fff;
    border-color:var(--admin-border);
}

.crm-message-top strong,
.crm-conversation-customer strong,
.crm-conversation-empty strong{
    color:var(--admin-navy);
}

.crm-message-top span,
.crm-message-subject,
.crm-conversation-customer span,
.crm-conversation-empty span{
    color:var(--admin-muted);
}

/* =========================
   PAGINATION
========================= */

.pagination{
    margin-top:12px;
}

.page-btn{
    min-width:33px;
    height:33px;
    padding:0 8px;
}

.page-btn.active{
    background:var(--admin-blue);
    border-color:var(--admin-blue);
    color:#fff;
}

/* =========================
   RESPONSIVE
========================= */

@media(max-width:1180px){
    .stats{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }

    .source-summary{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media(max-width:900px){
    body{
        overflow:auto;
    }

    .odisha-style-admin-layout{
        height:auto;
        min-height:100vh;
        overflow:visible;
    }

    .odisha-style-sidebar{
        width:205px;
        flex-basis:205px;
    }

    .odisha-style-main{
        width:calc(100% - 205px);
        height:auto;
        min-height:100vh;
        margin-left:205px;
        overflow:visible;
    }

    .page{
        height:auto;
        min-height:calc(100vh - 62px);
        padding:14px;
        overflow:visible;
    }

    .crm-workspace-tabs{
        top:0;
    }
}

@media(max-width:760px){
    .odisha-style-sidebar{
        display:none;
    }

    .odisha-style-main{
        width:100%;
        margin-left:0;
    }

    .topbar{
        height:58px;
        min-height:58px;
        padding:0 13px;
    }

    .odisha-style-topbar-title{
        font-size:13px;
    }

    .odisha-style-topbar-right{
        display:none;
    }

    .page{
        min-height:calc(100vh - 58px);
        padding:11px 9px 18px;
    }

    .page-head{
        align-items:flex-start;
        flex-direction:column;
        gap:8px;
    }

    .page-head h1{
        font-size:23px;
    }

    .page-head p{
        font-size:11px;
    }

    .stats{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .source-summary{
        grid-template-columns:1fr;
    }

    .crm-workspace-tabs{
        padding:5px;
        gap:4px;
        overflow-x:auto;
    }

    .crm-workspace-tab{
        min-height:33px;
        padding:0 8px;
        font-size:10px;
    }

    .card-head,
    .card-body{
        padding-left:11px;
        padding-right:11px;
    }

    .form-grid,
    .two-fields,
    .info-grid{
        grid-template-columns:1fr;
    }

    .control{
        min-height:42px;
        font-size:13px;
    }

    .button,
    .action{
        min-height:38px;
        font-size:11px;
    }

    th,
    td{
        padding:8px 7px;
    }
}

@media(max-width:430px){
    .stats{
        grid-template-columns:1fr 1fr;
    }

    .stat{
        min-height:70px;
        padding:10px;
    }

    .stat strong{
        font-size:20px;
    }

    .page{
        padding-left:7px;
        padding-right:7px;
    }
}

/* Old horizontal navigation stays hidden because the left admin sidebar is the standard. */
.brand,
.nav{
    display:none !important;
}



/* ============================================================
   CRM MAIN VIEW — GRAPHICAL PROFESSIONAL LAYOUT
   VISUAL / PLACEMENT ONLY. EXISTING CRM LOGIC UNCHANGED.
============================================================ */

/* Main page density */
.page{
    padding:16px 18px 20px !important;
}

.page-head{
    margin-bottom:11px !important;
    align-items:center !important;
}

.page-head h1{
    font-size:26px !important;
    line-height:1.12 !important;
}

.page-head p{
    margin-top:3px !important;
    font-size:11px !important;
    line-height:1.4 !important;
}

.page-actions{
    gap:7px !important;
}

/* KPI row — graphical color treatment */
.stats{
    grid-template-columns:repeat(5,minmax(0,1fr)) !important;
    gap:8px !important;
    margin-bottom:9px !important;
}

.stat{
    min-height:76px !important;
    padding:10px 12px !important;
    border-radius:10px !important;
    position:relative;
    overflow:hidden;
    box-shadow:0 5px 15px rgba(15,23,42,.045) !important;
}

.stat:before{
    content:"";
    position:absolute;
    inset:0 auto 0 0;
    width:4px;
}

.stat:nth-child(1){
    background:linear-gradient(135deg,#ffffff,#f0f6ff) !important;
    border-color:#dbe8fb !important;
}
.stat:nth-child(1):before{background:#2563eb}

.stat:nth-child(2){
    background:linear-gradient(135deg,#ffffff,#eefbf6) !important;
    border-color:#dcefe6 !important;
}
.stat:nth-child(2):before{background:#0f9a68}

.stat:nth-child(3){
    background:linear-gradient(135deg,#ffffff,#fff7ed) !important;
    border-color:#f4e2ce !important;
}
.stat:nth-child(3):before{background:#e58b2b}

.stat:nth-child(4){
    background:linear-gradient(135deg,#ffffff,#f5f1ff) !important;
    border-color:#e5ddf7 !important;
}
.stat:nth-child(4):before{background:#7c5bd1}

.stat:nth-child(5){
    background:linear-gradient(135deg,#ffffff,#eefaff) !important;
    border-color:#d9edf5 !important;
}
.stat:nth-child(5):before{background:#0b8fb3}

.stat span{
    font-size:9px !important;
    letter-spacing:.55px !important;
}

.stat strong{
    margin-top:4px !important;
    font-size:20px !important;
    line-height:1 !important;
}

/* Source summary becomes a compact horizontal analytical strip */
.source-summary{
    display:grid !important;
    grid-template-columns:repeat(4,minmax(0,1fr)) !important;
    gap:8px !important;
    margin-bottom:9px !important;
}

.source-box{
    min-height:66px !important;
    padding:9px 11px !important;
    border-radius:9px !important;
    position:relative;
    overflow:hidden;
}

.source-box:after{
    content:"";
    position:absolute;
    width:70px;
    height:70px;
    border-radius:50%;
    right:-24px;
    top:-30px;
    background:rgba(37,99,235,.06);
}

.source-box:nth-child(4n+1){
    background:linear-gradient(135deg,#f3f8ff,#fff) !important;
}
.source-box:nth-child(4n+2){
    background:linear-gradient(135deg,#effaf5,#fff) !important;
}
.source-box:nth-child(4n+3){
    background:linear-gradient(135deg,#fff7ed,#fff) !important;
}
.source-box:nth-child(4n+4){
    background:linear-gradient(135deg,#f6f2ff,#fff) !important;
}

.source-box strong{
    font-size:17px !important;
}

.source-box small{
    font-size:8px !important;
}

/* Filters — compact single management row */
.filters{
    display:grid !important;
    grid-template-columns:minmax(300px,2.15fr) repeat(3,minmax(150px,1fr)) auto !important;
    gap:7px !important;
    margin-bottom:7px !important;
}

.filters .control{
    min-height:36px !important;
    height:36px !important;
    font-size:11px !important;
}

.filters .button{
    min-height:36px !important;
    height:36px !important;
    padding:0 12px !important;
}

.view-tabs{
    margin-bottom:7px !important;
    gap:5px !important;
}

.view-tabs a{
    min-height:31px !important;
    padding:0 9px !important;
    font-size:9px !important;
}

/* Main lead card */
.card{
    margin-bottom:9px !important;
    border-radius:10px !important;
}

.card-head{
    min-height:45px !important;
    padding:8px 12px !important;
}

.card-head h2,
.card-head h3{
    font-size:14px !important;
}

.card-body{
    padding:10px 12px !important;
}

/* Table — compact but easy to scan */
.table-wrap{
    border-radius:8px !important;
}

table{
    font-size:10px !important;
}

th{
    padding:7px 8px !important;
    font-size:8px !important;
    line-height:1.2 !important;
}

td{
    padding:7px 8px !important;
    font-size:10px !important;
    line-height:1.28 !important;
    vertical-align:middle !important;
}

.strong{
    font-size:10px !important;
}

.small{
    font-size:8px !important;
}

.badge{
    min-height:21px !important;
    padding:0 6px !important;
    font-size:8px !important;
}

.action{
    min-height:29px !important;
    padding:0 8px !important;
    font-size:9px !important;
}

.actions{
    gap:4px !important;
}

/* Manage button stronger primary affordance */
.action.manage{
    background:linear-gradient(135deg,#2563eb,#3b82f6) !important;
    border-color:#2563eb !important;
    color:#fff !important;
    box-shadow:0 4px 10px rgba(37,99,235,.14) !important;
}

/* Pipeline cards */
.pipeline{
    gap:8px !important;
}

.pipe-column{
    border-radius:9px !important;
}

.pipe-head{
    min-height:38px !important;
    padding:8px 9px !important;
    font-size:10px !important;
}

.lead-card{
    padding:9px !important;
    margin:7px !important;
    border-radius:8px !important;
}

.lead-card h3{
    font-size:11px !important;
}

.lead-card p{
    font-size:9px !important;
}

/* Workspace navigation compact */
.crm-workspace-tabs{
    margin-bottom:8px !important;
    padding:5px !important;
    gap:4px !important;
}

.crm-workspace-tab{
    min-height:32px !important;
    padding:0 9px !important;
    font-size:10px !important;
}

/* Desktop: maximum use of horizontal space */
@media(min-width:1300px){
    .page{
        padding:14px 16px 18px !important;
    }

    .source-summary{
        grid-template-columns:repeat(5,minmax(0,1fr)) !important;
    }
}

/* Medium */
@media(max-width:1180px){
    .stats{
        grid-template-columns:repeat(3,minmax(0,1fr)) !important;
    }

    .source-summary{
        grid-template-columns:repeat(3,minmax(0,1fr)) !important;
    }

    .filters{
        grid-template-columns:1.7fr 1fr 1fr !important;
    }

    .filters .button{
        width:max-content;
    }
}

/* Tablet */
@media(max-width:820px){
    .page{
        padding:12px 9px 16px !important;
    }

    .page-head{
        align-items:flex-start !important;
        flex-direction:column !important;
    }

    .stats{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }

    .source-summary{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }

    .filters{
        grid-template-columns:1fr !important;
    }

    .filters .control,
    .filters .button{
        width:100% !important;
        min-height:40px !important;
        height:40px !important;
    }
}

/* Phone */
@media(max-width:480px){
    .stats,
    .source-summary{
        grid-template-columns:1fr !important;
    }

    .page-head h1{
        font-size:22px !important;
    }
}



/* ============================================================
   TRAVSCOPE MODERN LEFT PANEL — VISUAL ONLY
   Matches admin-dashboard.php navigation style.
   No PHP, database, form, action, link or page logic changed.
============================================================ */
.sidebar,
.admin-sidebar,
.odisha-style-sidebar{
    background:
        radial-gradient(circle at top left,rgba(40,117,255,.22),transparent 28%),
        radial-gradient(circle at bottom right,rgba(15,154,104,.12),transparent 24%),
        linear-gradient(180deg,#081225 0%,#0d1830 100%) !important;
    color:#fff !important;
    border-right:1px solid rgba(255,255,255,.06) !important;
    box-shadow:14px 0 38px rgba(4,10,24,.28),inset -1px 0 0 rgba(255,255,255,.03) !important;
}
.sidebar-logo,
.odisha-style-logo{
    margin:12px 12px 8px !important;
    padding:14px !important;
    min-height:68px !important;
    height:auto !important;
    border:1px solid rgba(255,255,255,.07) !important;
    border-radius:16px !important;
    background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.03)) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.06),0 10px 24px rgba(0,0,0,.16) !important;
}
.logo-icon,
.odisha-style-logo-icon{
    box-shadow:0 10px 24px rgba(37,99,235,.28) !important;
}
.sidebar-menu{padding:6px 10px 14px !important;}
.menu-title,
.sidebar-title,
.odisha-style-menu-label{
    margin:16px 10px 8px !important;
    padding:0 2px !important;
    color:rgba(183,202,234,.70) !important;
    font-size:10px !important;
    font-weight:800 !important;
    letter-spacing:.13em !important;
    text-transform:uppercase !important;
}
.menu-link,
.odisha-style-nav-link,
.admin-sidebar a{
    position:relative !important;
    min-height:46px !important;
    margin:0 4px 7px !important;
    padding:8px 13px 8px 12px !important;
    border:1px solid transparent !important;
    border-radius:13px !important;
    display:flex !important;
    align-items:center !important;
    gap:11px !important;
    background:transparent !important;
    color:rgba(226,236,255,.76) !important;
    font-size:13px !important;
    font-weight:700 !important;
    line-height:1.15 !important;
    transition:transform .16s ease,background .16s ease,border-color .16s ease,color .16s ease,box-shadow .16s ease !important;
}
.menu-link:hover,
.odisha-style-nav-link:hover,
.admin-sidebar a:hover{
    transform:translateX(2px) !important;
    color:#fff !important;
    border-color:rgba(255,255,255,.08) !important;
    background:rgba(255,255,255,.045) !important;
    box-shadow:0 8px 20px rgba(0,0,0,.14) !important;
}
.menu-link i,
.odisha-style-nav-icon,
.admin-sidebar a i{
    width:30px !important;
    height:30px !important;
    flex:0 0 30px !important;
    display:grid !important;
    place-items:center !important;
    border-radius:10px !important;
    background:rgba(255,255,255,.06) !important;
    color:#cfe0ff !important;
    font-size:12px !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04) !important;
}
.menu-link.active,
.odisha-style-nav-link.active,
.admin-sidebar a.active{
    color:#fff !important;
    border-color:rgba(75,138,255,.36) !important;
    background:linear-gradient(135deg,rgba(37,99,235,.95),rgba(80,140,255,.95)) !important;
    box-shadow:0 12px 24px rgba(15,23,42,.26),0 0 0 1px rgba(255,255,255,.03) inset !important;
}
.menu-link.active i,
.odisha-style-nav-link.active .odisha-style-nav-icon,
.admin-sidebar a.active i{
    background:rgba(255,255,255,.18) !important;
    color:#fff !important;
}
.menu-link.active::after,
.odisha-style-nav-link.active::after,
.admin-sidebar a.active::after{
    content:"";
    position:absolute;
    left:-4px;
    top:9px;
    bottom:9px;
    width:4px;
    border-radius:999px;
    background:linear-gradient(180deg,#8dc2ff 0%,#fff 100%);
    box-shadow:0 0 12px rgba(141,194,255,.7);
}
.menu-badge,
.odisha-style-nav-badge{
    min-width:22px !important;
    height:22px !important;
    padding:0 6px !important;
    margin-left:auto !important;
    border-radius:999px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:rgba(255,255,255,.10) !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.09) !important;
    font-size:10px !important;
    font-weight:800 !important;
}
.menu-link[href*="logout"],
.odisha-style-nav-link[href*="logout"],
.admin-sidebar a[href*="logout"]{
    margin-top:12px !important;
    border-color:rgba(255,107,107,.15) !important;
    background:rgba(255,78,78,.06) !important;
    color:#ffd6d6 !important;
}


/* ============================================================
   MODERN PROFESSIONAL SIDEBAR NAVIGATION
   VISUAL ONLY — LINKS, COUNTS AND DASHBOARD LOGIC UNCHANGED
============================================================ */

.sidebar{
    width:265px !important;
    background:
        linear-gradient(180deg,#0b172a 0%,#101d33 58%,#0c1829 100%) !important;
    border-right:1px solid rgba(255,255,255,.045) !important;
    box-shadow:10px 0 30px rgba(10,24,43,.08) !important;
}

.sidebar-logo{
    height:72px !important;
    padding:0 17px !important;
    gap:10px !important;
    border-bottom:1px solid rgba(255,255,255,.08) !important;
    font-size:16px !important;
    letter-spacing:-.2px !important;
}

.sidebar-logo .logo-icon{
    width:38px !important;
    height:38px !important;
    border-radius:11px !important;
    background:linear-gradient(135deg,#1577ff,#0bb9ee) !important;
    box-shadow:0 7px 18px rgba(11,116,255,.25) !important;
}

.sidebar-menu{
    padding:12px 10px 24px !important;
}

/* Group titles */
.menu-title{
    position:relative !important;
    margin:9px 7px 6px !important;
    padding:7px 8px 7px 10px !important;
    color:#75869c !important;
    font-size:9px !important;
    font-weight:800 !important;
    letter-spacing:1.1px !important;
    line-height:1 !important;
}

.menu-title:before{
    content:"";
    position:absolute;
    left:0;
    top:50%;
    width:3px;
    height:13px;
    border-radius:999px;
    transform:translateY(-50%);
    background:#2d7ff9;
    opacity:.55;
}

/* Navigation buttons */
.menu-link{
    min-height:42px !important;
    margin:3px 0 !important;
    padding:0 9px !important;
    gap:9px !important;
    border:1px solid transparent !important;
    border-radius:10px !important;
    color:#aebbd0 !important;
    font-size:11px !important;
    font-weight:700 !important;
    transition:
        background .16s ease,
        border-color .16s ease,
        color .16s ease,
        transform .16s ease,
        box-shadow .16s ease !important;
}

.menu-link i{
    width:29px !important;
    height:29px !important;
    flex:0 0 29px !important;
    display:grid !important;
    place-items:center !important;
    border-radius:8px !important;
    background:rgba(255,255,255,.055) !important;
    color:#90a3bb !important;
    font-size:11px !important;
    transition:.16s ease !important;
}

.menu-link:hover{
    color:#fff !important;
    background:rgba(255,255,255,.055) !important;
    border-color:rgba(255,255,255,.055) !important;
    transform:translateX(2px) !important;
}

.menu-link:hover i{
    background:rgba(255,255,255,.10) !important;
    color:#fff !important;
}

/* Active item */
.menu-link.active{
    color:#fff !important;
    background:
        linear-gradient(135deg,#2563eb 0%,#3b82f6 58%,#2875f0 100%) !important;
    border-color:rgba(119,173,255,.45) !important;
    box-shadow:
        0 7px 16px rgba(37,99,235,.23),
        inset 0 1px 0 rgba(255,255,255,.14) !important;
}

.menu-link.active i{
    background:rgba(255,255,255,.18) !important;
    color:#fff !important;
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.10) !important;
}

/* Give menu categories subtle individual icon colours */
.sidebar-menu .menu-link[href*="crm"] i{
    background:rgba(124,91,209,.14) !important;
    color:#b5a1f8 !important;
}

.sidebar-menu .menu-link[href*="destination"] i{
    background:rgba(14,165,233,.13) !important;
    color:#77d2f3 !important;
}

.sidebar-menu .menu-link[href*="package"] i{
    background:rgba(245,158,11,.14) !important;
    color:#f7bf5b !important;
}

.sidebar-menu .menu-link[href*="gallery"] i{
    background:rgba(16,185,129,.13) !important;
    color:#67dcb6 !important;
}

.sidebar-menu .menu-link[href*="booking"] i{
    background:rgba(99,102,241,.14) !important;
    color:#a8aaf7 !important;
}

.sidebar-menu .menu-link[href*="cancellation"] i{
    background:rgba(244,63,94,.14) !important;
    color:#f394a6 !important;
}

.sidebar-menu .menu-link[href*="coupon"] i{
    background:rgba(6,182,212,.13) !important;
    color:#72d8e9 !important;
}

.sidebar-menu .menu-link[href*="enquir"] i{
    background:rgba(59,130,246,.14) !important;
    color:#8db9f6 !important;
}

.sidebar-menu .menu-link[href*="customer"] i,
.sidebar-menu .menu-link[href*="users"] i{
    background:rgba(20,184,166,.13) !important;
    color:#72d7ca !important;
}

.sidebar-menu .menu-link[href*="review"] i{
    background:rgba(234,179,8,.14) !important;
    color:#f4d35f !important;
}

.sidebar-menu .menu-link[href*="blog"] i,
.sidebar-menu .menu-link[href*="story"] i{
    background:rgba(168,85,247,.14) !important;
    color:#c9a0f8 !important;
}

.sidebar-menu .menu-link[href*="settings"] i{
    background:rgba(148,163,184,.14) !important;
    color:#bac5d2 !important;
}

.sidebar-menu .menu-link[href*="website"] i{
    background:rgba(34,197,94,.13) !important;
    color:#72dc92 !important;
}

/* Active icon always follows active design */
.sidebar-menu .menu-link.active i{
    background:rgba(255,255,255,.18) !important;
    color:#fff !important;
}

/* Modern badges */
.menu-badge{
    min-width:21px !important;
    height:21px !important;
    padding:0 6px !important;
    border:1px solid rgba(255,255,255,.13) !important;
    border-radius:999px !important;
    background:linear-gradient(135deg,#1d75db,#3189ef) !important;
    color:#fff !important;
    font-size:9px !important;
    font-weight:800 !important;
    box-shadow:0 3px 8px rgba(0,0,0,.15) !important;
}

/* Override old inline badge colours for consistent modern look */
.menu-link .menu-badge[style]{
    background:linear-gradient(135deg,#1d75db,#3189ef) !important;
}

/* CRM badge slightly purple */
.sidebar-menu .menu-link[href*="crm"] .menu-badge{
    background:linear-gradient(135deg,#7450d5,#9369ed) !important;
}

/* Destructive / alert type badge */
.sidebar-menu .menu-link[href*="cancellation"] .menu-badge{
    background:linear-gradient(135deg,#d84b61,#ec6377) !important;
}

/* Bottom website/settings area feels separated */
.sidebar-menu .menu-title:last-of-type{
    margin-top:14px !important;
}

/* Slim scrollbar */
.sidebar{
    scrollbar-width:thin;
    scrollbar-color:#314158 transparent;
}

.sidebar::-webkit-scrollbar{
    width:5px;
}

.sidebar::-webkit-scrollbar-thumb{
    background:#314158;
    border-radius:999px;
}

.sidebar::-webkit-scrollbar-track{
    background:transparent;
}

/* Keep main alignment exactly with sidebar width */
.main{
    margin-left:265px !important;
}

@media(max-width:850px){
    .main{
        margin-left:0 !important;
    }

    .sidebar{
        width:265px !important;
    }
}



/* ============================================================
   TRAVSCOPE MODERN LEFT PANEL — VISUAL ONLY
   Matches admin-dashboard.php navigation style.
   No PHP, database, form, action, link or page logic changed.
============================================================ */
.sidebar,
.admin-sidebar,
.odisha-style-sidebar{
    background:
        radial-gradient(circle at top left,rgba(40,117,255,.22),transparent 28%),
        radial-gradient(circle at bottom right,rgba(15,154,104,.12),transparent 24%),
        linear-gradient(180deg,#081225 0%,#0d1830 100%) !important;
    color:#fff !important;
    border-right:1px solid rgba(255,255,255,.06) !important;
    box-shadow:14px 0 38px rgba(4,10,24,.28),inset -1px 0 0 rgba(255,255,255,.03) !important;
}
.sidebar-logo,
.odisha-style-logo{
    margin:12px 12px 8px !important;
    padding:14px !important;
    min-height:68px !important;
    height:auto !important;
    border:1px solid rgba(255,255,255,.07) !important;
    border-radius:16px !important;
    background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.03)) !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.06),0 10px 24px rgba(0,0,0,.16) !important;
}
.logo-icon,
.odisha-style-logo-icon{
    box-shadow:0 10px 24px rgba(37,99,235,.28) !important;
}
.sidebar-menu{padding:6px 10px 14px !important;}
.menu-title,
.sidebar-title,
.odisha-style-menu-label{
    margin:16px 10px 8px !important;
    padding:0 2px !important;
    color:rgba(183,202,234,.70) !important;
    font-size:10px !important;
    font-weight:800 !important;
    letter-spacing:.13em !important;
    text-transform:uppercase !important;
}
.menu-link,
.odisha-style-nav-link,
.admin-sidebar a{
    position:relative !important;
    min-height:46px !important;
    margin:0 4px 7px !important;
    padding:8px 13px 8px 12px !important;
    border:1px solid transparent !important;
    border-radius:13px !important;
    display:flex !important;
    align-items:center !important;
    gap:11px !important;
    background:transparent !important;
    color:rgba(226,236,255,.76) !important;
    font-size:13px !important;
    font-weight:700 !important;
    line-height:1.15 !important;
    transition:transform .16s ease,background .16s ease,border-color .16s ease,color .16s ease,box-shadow .16s ease !important;
}
.menu-link:hover,
.odisha-style-nav-link:hover,
.admin-sidebar a:hover{
    transform:translateX(2px) !important;
    color:#fff !important;
    border-color:rgba(255,255,255,.08) !important;
    background:rgba(255,255,255,.045) !important;
    box-shadow:0 8px 20px rgba(0,0,0,.14) !important;
}
.menu-link i,
.odisha-style-nav-icon,
.admin-sidebar a i{
    width:30px !important;
    height:30px !important;
    flex:0 0 30px !important;
    display:grid !important;
    place-items:center !important;
    border-radius:10px !important;
    background:rgba(255,255,255,.06) !important;
    color:#cfe0ff !important;
    font-size:12px !important;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.04) !important;
}
.menu-link.active,
.odisha-style-nav-link.active,
.admin-sidebar a.active{
    color:#fff !important;
    border-color:rgba(75,138,255,.36) !important;
    background:linear-gradient(135deg,rgba(37,99,235,.95),rgba(80,140,255,.95)) !important;
    box-shadow:0 12px 24px rgba(15,23,42,.26),0 0 0 1px rgba(255,255,255,.03) inset !important;
}
.menu-link.active i,
.odisha-style-nav-link.active .odisha-style-nav-icon,
.admin-sidebar a.active i{
    background:rgba(255,255,255,.18) !important;
    color:#fff !important;
}
.menu-link.active::after,
.odisha-style-nav-link.active::after,
.admin-sidebar a.active::after{
    content:"";
    position:absolute;
    left:-4px;
    top:9px;
    bottom:9px;
    width:4px;
    border-radius:999px;
    background:linear-gradient(180deg,#8dc2ff 0%,#fff 100%);
    box-shadow:0 0 12px rgba(141,194,255,.7);
}
.menu-badge,
.odisha-style-nav-badge{
    min-width:22px !important;
    height:22px !important;
    padding:0 6px !important;
    margin-left:auto !important;
    border-radius:999px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:rgba(255,255,255,.10) !important;
    color:#fff !important;
    border:1px solid rgba(255,255,255,.09) !important;
    font-size:10px !important;
    font-weight:800 !important;
}
.menu-link[href*="logout"],
.odisha-style-nav-link[href*="logout"],
.admin-sidebar a[href*="logout"]{
    margin-top:12px !important;
    border-color:rgba(255,107,107,.15) !important;
    background:rgba(255,78,78,.06) !important;
    color:#ffd6d6 !important;
}



/* === EXACT DASHBOARD SIDEBAR — CROSS-ADMIN COMPATIBILITY === */
.menu-badge:empty{display:none!important}
.sidebar .menu-link{box-sizing:border-box!important}
.sidebar .menu-link span:not(.menu-badge){min-width:0}

/* Existing admin layouts with header above sidebar */
.layout > .sidebar{top:0!important}
.layout > .main{min-width:0}

/* CRM uses a different main wrapper */
.odisha-style-main{margin-left:265px!important;min-width:0!important}

/* Detail pages that originally had no navigation sidebar */
body.ts-dashboard-sidebar-added > .topbar{margin-left:265px!important;width:auto!important}
body.ts-dashboard-sidebar-added > .page{margin-left:265px!important;width:auto!important;max-width:none!important}
body.ts-dashboard-sidebar-added > main.page{padding-left:22px!important;padding-right:22px!important}

@media(max-width:850px){
    .odisha-style-main{margin-left:0!important}
    body.ts-dashboard-sidebar-added > .topbar{margin-left:0!important}
    body.ts-dashboard-sidebar-added > .page{margin-left:0!important}
}


/* ============================================================
   DASHBOARD-ALIGNED ADMIN WORKSPACE
   Layout spacing only: remove duplicate sidebar gap and keep
   content immediately beside the fixed 265px dashboard sidebar.
============================================================ */
:root{--ts-admin-sidebar-width:265px}

.page{
    margin:0 0 0 var(--ts-admin-sidebar-width)!important;
    width:auto!important;
    max-width:none!important;
    min-width:0!important;
    padding:16px 18px 20px!important;
}
.admin-main{
    margin-left:var(--ts-admin-sidebar-width)!important;
    width:auto!important;
    max-width:none!important;
}

@media(max-width:850px){
    .main,.page,.admin-main{
        margin-left:0!important;
    }
    .main{padding:14px 12px 18px!important;}
    .page{padding:14px 12px 18px!important;}
    .main > .content,.main .content:first-child{padding:14px 12px 18px!important;}
}



/* ============================================================
   TRAVSCOPE FINAL ADMIN LAYOUT STANDARD
   Dashboard-aligned, edge-to-edge workspace, no page-sideways
   overflow, responsive on desktop/tablet/mobile.
   VISUAL/LAYOUT ONLY — business logic untouched.
============================================================ */

html,
body{
    width:100% !important;
    max-width:100% !important;
    overflow-x:hidden !important;
}

/* Fixed dashboard-standard sidebar */
.sidebar,
.admin-sidebar,
.odisha-style-sidebar{
    width:265px !important;
    min-width:265px !important;
    max-width:265px !important;
    position:fixed !important;
    left:0 !important;
    top:0 !important;
    bottom:0 !important;
    z-index:1100 !important;
}

/* Root wrappers must not reserve a second sidebar column */
.layout,
.admin-shell,
.odisha-style-admin-layout{
    width:100% !important;
    max-width:100% !important;
    min-width:0 !important;
}

/* Common root layout wrappers containing the fixed sidebar */
.layout{
    display:block !important;
}

/* Main workspace: identical horizontal start to dashboard */
.main,
.admin-main,
.odisha-style-main{
    margin-left:265px !important;
    width:calc(100% - 265px) !important;
    max-width:none !important;
    min-width:0 !important;
    box-sizing:border-box !important;
}

/* Standalone page/content roots used by CRM/enquiry/detail screens */
body > .page,
body > .content{
    margin-left:265px !important;
    width:calc(100% - 265px) !important;
    max-width:none !important;
    min-width:0 !important;
    box-sizing:border-box !important;
}

/* Nested pages must fill their main workspace, never recentre/narrow */
.main > .page,
.main .page,
.admin-main > .page,
.admin-main .page,
.odisha-style-main > .page,
.odisha-style-main .page,
.main > .content,
.admin-main > .content,
.odisha-style-main > .content{
    width:100% !important;
    max-width:none !important;
    min-width:0 !important;
    margin-left:0 !important;
    margin-right:0 !important;
}

/* Dashboard-like visual breathing room */
.main,
.admin-main,
.odisha-style-main,
body > .page,
body > .content{
    padding-left:16px !important;
    padding-right:16px !important;
}

.page,
.content,
.card,
.panel,
.profile-manager,
.form-wrap,
.editor-shell,
.settings-content,
.table-wrap,
.content-grid,
.details-grid,
.info-grid,
.summary-grid,
.customer-grid,
.form-grid,
.filters,
.grid{
    min-width:0 !important;
    max-width:100% !important;
    box-sizing:border-box !important;
}

/* Prevent nested width declarations from pushing the entire page sideways */
.card,
.panel,
.form-wrap,
.editor-shell,
.settings-content,
.profile-manager{
    width:auto !important;
}

/* Tables scroll inside their own card only — never the whole admin page */
.table-wrap,
.pricing-table-wrap,
.season-price-table-wrap{
    width:100% !important;
    max-width:100% !important;
    overflow-x:auto !important;
    overflow-y:hidden;
    -webkit-overflow-scrolling:touch;
}

table,
.booking-table,
.customer-table,
.destination-table,
.pricing-table{
    max-width:none;
}

/* Media stays inside its card */
img,
video,
canvas{
    max-width:100%;
}

/* Common data grids adapt instead of forcing horizontal page scroll */
.stats,
.stats-grid,
.mini-stats,
.overview-grid,
.summary-grid,
.details-grid,
.info-grid,
.content-grid,
finance-grid,
.money-grid,
.action-grid{
    min-width:0 !important;
}

/* Desktop professional spacing */
.page-head,
.page-heading{
    margin-left:0 !important;
    margin-right:0 !important;
}

@media(min-width:851px){
    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        padding-top:16px !important;
        padding-bottom:20px !important;
    }

    /* CRM / enquiry pages that previously used centered 94–97% widths */
    .page{
        width:100% !important;
        max-width:none !important;
    }
}

/* Tablet: sidebar collapses exactly like dashboard */
@media(max-width:850px){
    .sidebar,
    .admin-sidebar,
    .odisha-style-sidebar{
        width:265px !important;
        min-width:265px !important;
        max-width:265px !important;
        transform:translateX(-100%);
    }

    .sidebar.open,
    .admin-sidebar.open,
    .odisha-style-sidebar.open{
        transform:translateX(0) !important;
    }

    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        margin-left:0 !important;
        width:100% !important;
        max-width:100% !important;
        padding-left:12px !important;
        padding-right:12px !important;
    }

    .layout,
    .admin-shell,
    .odisha-style-admin-layout{
        display:block !important;
        width:100% !important;
    }

    .page,
    .content{
        width:100% !important;
        max-width:100% !important;
        margin-left:0 !important;
        margin-right:0 !important;
    }

    .stats,
    .stats-grid,
    .mini-stats{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }

    .filters,
    .form-grid,
    .details-grid,
    .info-grid,
    .summary-grid,
    .content-grid{
        grid-template-columns:1fr !important;
    }
}

/* Phone */
@media(max-width:600px){
    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        padding-left:8px !important;
        padding-right:8px !important;
        padding-top:10px !important;
    }

    .stats,
    .stats-grid,
    .mini-stats,
    .overview-grid{
        grid-template-columns:1fr !important;
    }

    .page-head,
    .page-heading{
        gap:8px !important;
    }

    .page-head h1,
    .page-heading h1{
        font-size:22px !important;
    }

    /* Buttons/forms remain touch-friendly */
    input:not([type="checkbox"]):not([type="radio"]),
    select,
    textarea,
    .control,
    .form-control{
        max-width:100% !important;
    }
}



/* ============================================================
   TRAVSCOPE STABLE COMPACT SIDEBAR
   Same exact dimensions/position on every admin page.
   No desktop jump, slide, resize, or layout shift.
============================================================ */

:root{
    --ts-sidebar-width:230px;
    --ts-sidebar-bg:#09162b;
    --ts-sidebar-bg-2:#0d1c35;
    --ts-sidebar-text:#b9c7d9;
    --ts-sidebar-heading:#70839f;
    --ts-sidebar-active:#2f7df4;
}

/* Disable visual shifting on desktop */
.sidebar,
.admin-sidebar,
.odisha-style-sidebar,
.main,
.admin-main,
.odisha-style-main,
body > .page,
body > .content{
    transition:none !important;
    animation:none !important;
}

/* Exact same compact sidebar everywhere */
.sidebar,
.admin-sidebar,
.odisha-style-sidebar{
    position:fixed !important;
    top:0 !important;
    left:0 !important;
    bottom:0 !important;
    width:var(--ts-sidebar-width) !important;
    min-width:var(--ts-sidebar-width) !important;
    max-width:var(--ts-sidebar-width) !important;
    height:100vh !important;
    background:
        linear-gradient(180deg,var(--ts-sidebar-bg) 0%,var(--ts-sidebar-bg-2) 100%) !important;
    overflow:hidden !important;
    z-index:1100 !important;
    box-shadow:8px 0 24px rgba(8,18,38,.16) !important;
}

/* Compact logo — identical height on every page */
.sidebar-logo{
    height:64px !important;
    min-height:64px !important;
    margin:8px 9px 5px !important;
    padding:0 11px !important;
    gap:9px !important;
    border:1px solid rgba(255,255,255,.065) !important;
    border-radius:12px !important;
    background:rgba(255,255,255,.035) !important;
    font-size:15px !important;
    line-height:1 !important;
    box-shadow:none !important;
}

.sidebar-logo .logo-icon,
.sidebar-logo > .logo-icon{
    width:34px !important;
    height:34px !important;
    flex:0 0 34px !important;
    border-radius:9px !important;
    font-size:12px !important;
    transform:none !important;
}

/* Fit the full menu without the panel moving up/down */
.sidebar-menu{
    height:calc(100vh - 77px) !important;
    padding:3px 8px 8px !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    scrollbar-width:none !important;
    overscroll-behavior:contain !important;
}

.sidebar-menu::-webkit-scrollbar{
    width:0 !important;
    height:0 !important;
}

/* Small organized section headings */
.menu-title{
    height:22px !important;
    min-height:22px !important;
    margin:4px 5px 2px !important;
    padding:6px 4px 2px !important;
    display:flex !important;
    align-items:flex-end !important;
    color:var(--ts-sidebar-heading) !important;
    font-size:8px !important;
    font-weight:800 !important;
    line-height:1 !important;
    letter-spacing:1px !important;
    white-space:nowrap !important;
}

/* Compact fixed-height menu buttons */
.menu-link,
.sidebar .menu-link{
    position:relative !important;
    width:100% !important;
    height:36px !important;
    min-height:36px !important;
    max-height:36px !important;
    margin:0 0 2px !important;
    padding:0 9px !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
    border:1px solid transparent !important;
    border-radius:9px !important;
    background:transparent !important;
    color:var(--ts-sidebar-text) !important;
    font-size:11px !important;
    font-weight:700 !important;
    line-height:1 !important;
    white-space:nowrap !important;
    transform:none !important;
    box-shadow:none !important;
    transition:none !important;
}

/* Same icon box size everywhere */
.menu-link i,
.sidebar .menu-link i{
    width:24px !important;
    height:24px !important;
    min-width:24px !important;
    flex:0 0 24px !important;
    margin:0 !important;
    display:grid !important;
    place-items:center !important;
    border-radius:7px !important;
    background:rgba(255,255,255,.055) !important;
    color:#a9c9f8 !important;
    font-size:10px !important;
    line-height:1 !important;
}

/* Hover does not move the button */
.menu-link:hover,
.sidebar .menu-link:hover{
    transform:none !important;
    background:rgba(255,255,255,.055) !important;
    border-color:rgba(255,255,255,.055) !important;
    color:#fff !important;
    box-shadow:none !important;
}

/* Current page: only color changes, dimensions remain identical */
.menu-link.active,
.sidebar .menu-link.active{
    height:36px !important;
    min-height:36px !important;
    padding:0 9px !important;
    transform:none !important;
    background:linear-gradient(135deg,#256ee9,#3d8af7) !important;
    border-color:rgba(104,164,255,.42) !important;
    color:#fff !important;
    box-shadow:0 4px 12px rgba(23,92,210,.18) !important;
}

.menu-link.active i,
.sidebar .menu-link.active i{
    background:rgba(255,255,255,.16) !important;
    color:#fff !important;
}

/* Remove active side stripe that can visually shift */
.menu-link.active::after{
    display:none !important;
}

.menu-link::before{
    display:none !important;
}

/* Fixed small counters */
.menu-badge{
    margin-left:auto !important;
    min-width:18px !important;
    width:auto !important;
    height:18px !important;
    padding:0 5px !important;
    border-radius:999px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    background:rgba(255,255,255,.12) !important;
    border:0 !important;
    color:#fff !important;
    font-size:8px !important;
    font-weight:800 !important;
    line-height:1 !important;
    box-shadow:none !important;
}

/* Do not treat logout as a different-size button */
.menu-link[href*="logout"]{
    margin-top:2px !important;
    height:36px !important;
    min-height:36px !important;
}

/* Every desktop page begins at the exact same X coordinate */
.main,
.admin-main,
.odisha-style-main{
    margin-left:var(--ts-sidebar-width) !important;
    width:calc(100% - var(--ts-sidebar-width)) !important;
    max-width:none !important;
}

body > .page,
body > .content{
    margin-left:var(--ts-sidebar-width) !important;
    width:calc(100% - var(--ts-sidebar-width)) !important;
    max-width:none !important;
}

/* Do not let nested pages add their own left gap */
.main > .page,
.main .page,
.admin-main > .page,
.admin-main .page,
.odisha-style-main > .page,
.odisha-style-main .page,
.main > .content,
.admin-main > .content,
.odisha-style-main > .content{
    margin-left:0 !important;
    width:100% !important;
    max-width:none !important;
}

/* Mobile retains slide drawer; desktop remains completely fixed */
@media(max-width:850px){
    .sidebar,
    .admin-sidebar,
    .odisha-style-sidebar{
        width:230px !important;
        min-width:230px !important;
        max-width:230px !important;
        overflow-y:auto !important;
        transform:translateX(-100%) !important;
        transition:transform .18s ease !important;
    }

    .sidebar.open,
    .admin-sidebar.open,
    .odisha-style-sidebar.open{
        transform:translateX(0) !important;
    }

    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        margin-left:0 !important;
        width:100% !important;
    }
}



/* ============================================================
   TRAVSCOPE CONSTANT ADMIN LEFT PANEL
   IDENTICAL ON EVERY ADMIN PHP.
   Only active menu item and page content change.
============================================================ */

:root{
    --ts-fixed-sidebar:230px;
    --ts-side-bg:#09162b;
    --ts-side-bg2:#0d1c35;
    --ts-side-text:#bdc9d9;
    --ts-side-muted:#71839e;
    --ts-side-blue:#2f7df4;
}

/* Lock sidebar dimensions before page content paints */
html{overflow-x:hidden!important}
body{overflow-x:hidden!important}

.sidebar{
    position:fixed!important;
    inset:0 auto 0 0!important;
    width:var(--ts-fixed-sidebar)!important;
    min-width:var(--ts-fixed-sidebar)!important;
    max-width:var(--ts-fixed-sidebar)!important;
    height:100vh!important;
    z-index:1100!important;

    display:flex!important;
    flex-direction:column!important;

    background:
        radial-gradient(circle at 15% 0%,rgba(55,126,255,.14),transparent 23%),
        linear-gradient(180deg,var(--ts-side-bg),var(--ts-side-bg2))!important;

    color:#fff!important;
    border-right:1px solid rgba(255,255,255,.055)!important;
    box-shadow:8px 0 24px rgba(7,18,39,.15)!important;

    overflow:hidden!important;
    transform:none!important;
    transition:none!important;
    animation:none!important;
}

/* Brand block */
.sidebar-logo{
    flex:0 0 62px!important;
    height:62px!important;
    min-height:62px!important;

    margin:8px 9px 5px!important;
    padding:0 11px!important;

    display:flex!important;
    align-items:center!important;
    gap:9px!important;

    border:1px solid rgba(255,255,255,.07)!important;
    border-radius:12px!important;
    background:rgba(255,255,255,.035)!important;

    color:#fff!important;
    font-family:'Manrope','DM Sans',Arial,sans-serif!important;
    font-size:14px!important;
    font-weight:800!important;
    letter-spacing:0!important;
    line-height:1!important;

    box-shadow:none!important;
    transition:none!important;
}

.sidebar-logo .logo-icon{
    width:34px!important;
    height:34px!important;
    min-width:34px!important;
    flex:0 0 34px!important;

    display:grid!important;
    place-items:center!important;

    border-radius:9px!important;
    background:linear-gradient(135deg,#1688ff,#27b7e9)!important;
    color:#fff!important;
    font-size:12px!important;

    transform:none!important;
    box-shadow:0 5px 12px rgba(22,136,255,.18)!important;
}

.sidebar-logo span{
    color:#6db7ff!important;
}

/* Menu body */
.sidebar-menu{
    flex:1 1 auto!important;
    min-height:0!important;

    padding:2px 8px 8px!important;

    overflow-y:auto!important;
    overflow-x:hidden!important;

    scrollbar-width:none!important;
    overscroll-behavior:contain!important;
}
.sidebar-menu::-webkit-scrollbar{display:none!important}

/* Section labels */
.menu-title{
    height:21px!important;
    min-height:21px!important;
    margin:4px 5px 2px!important;
    padding:5px 4px 2px!important;

    display:flex!important;
    align-items:flex-end!important;

    color:var(--ts-side-muted)!important;
    font-size:8px!important;
    font-weight:800!important;
    line-height:1!important;
    letter-spacing:1px!important;
    text-transform:uppercase!important;

    white-space:nowrap!important;
}

/* Every button uses EXACT same geometry */
.menu-link{
    position:relative!important;

    width:100%!important;
    height:35px!important;
    min-height:35px!important;
    max-height:35px!important;

    margin:0 0 2px!important;
    padding:0 9px!important;

    display:flex!important;
    align-items:center!important;
    gap:8px!important;

    border:1px solid transparent!important;
    border-radius:9px!important;

    background:transparent!important;
    color:var(--ts-side-text)!important;

    font-size:11px!important;
    font-weight:700!important;
    line-height:1!important;

    white-space:nowrap!important;

    box-shadow:none!important;
    transform:none!important;
    transition:none!important;
    animation:none!important;
}

.menu-link i{
    width:23px!important;
    height:23px!important;
    min-width:23px!important;
    flex:0 0 23px!important;

    margin:0!important;

    display:grid!important;
    place-items:center!important;

    border-radius:7px!important;
    background:rgba(255,255,255,.055)!important;
    color:#a8c8f6!important;

    font-size:10px!important;
    line-height:1!important;

    transition:none!important;
}

/* Hover only changes colour — never size/position */
.menu-link:hover{
    background:rgba(255,255,255,.055)!important;
    border-color:rgba(255,255,255,.055)!important;
    color:#fff!important;

    transform:none!important;
    box-shadow:none!important;
}
.menu-link:hover i{
    background:rgba(255,255,255,.085)!important;
    color:#fff!important;
}

/* Active state keeps EXACT same geometry */
.menu-link.active{
    width:100%!important;
    height:35px!important;
    min-height:35px!important;
    max-height:35px!important;

    margin:0 0 2px!important;
    padding:0 9px!important;

    background:linear-gradient(135deg,#246fe9,#3b88f6)!important;
    border-color:rgba(105,166,255,.35)!important;
    color:#fff!important;

    box-shadow:0 4px 11px rgba(35,111,233,.16)!important;
    transform:none!important;
}
.menu-link.active i{
    background:rgba(255,255,255,.16)!important;
    color:#fff!important;
}

.menu-link::before,
.menu-link::after{
    display:none!important;
}

/* Constant compact badges */
.menu-badge{
    margin-left:auto!important;
    min-width:18px!important;
    height:18px!important;
    padding:0 5px!important;

    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;

    border:0!important;
    border-radius:999px!important;

    background:rgba(255,255,255,.12)!important;
    color:#fff!important;

    font-size:8px!important;
    font-weight:800!important;
    line-height:1!important;

    box-shadow:none!important;
}

/* Main content always begins immediately after same sidebar */
.main,
.admin-main,
.odisha-style-main{
    margin-left:var(--ts-fixed-sidebar)!important;
    width:calc(100% - var(--ts-fixed-sidebar))!important;
    max-width:none!important;
    min-width:0!important;
}

body > .page,
body > .content{
    margin-left:var(--ts-fixed-sidebar)!important;
    width:calc(100% - var(--ts-fixed-sidebar))!important;
    max-width:none!important;
    min-width:0!important;
}

/* No second sidebar gap from old grid layouts */
.layout,
.admin-shell,
.odisha-style-admin-layout{
    display:block!important;
    width:100%!important;
    max-width:100%!important;
    min-width:0!important;
}

.main > .page,
.main .page,
.admin-main > .page,
.admin-main .page,
.odisha-style-main > .page,
.odisha-style-main .page,
.main > .content,
.admin-main > .content,
.odisha-style-main > .content{
    margin-left:0!important;
    margin-right:0!important;
    width:100%!important;
    max-width:none!important;
}

/* Mobile drawer only */
@media(max-width:850px){
    .sidebar{
        width:230px!important;
        min-width:230px!important;
        max-width:230px!important;
        transform:translateX(-100%)!important;
        transition:transform .18s ease!important;
    }

    .sidebar.open{
        transform:translateX(0)!important;
    }

    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content{
        margin-left:0!important;
        width:100%!important;
        max-width:100%!important;
    }
}



/* ============================================================
   TRAVSCOPE CONSTANT SIDEBAR BEHAVIOUR
   Desktop: panel never moves.
   Mobile/tablet: one universal 3-line hamburger drawer.
============================================================ */

/* Universal mobile hamburger stays hidden on desktop */
.ts-mobile-sidebar-toggle{
    display:none;
    position:fixed;
    top:12px;
    left:12px;
    z-index:1305;

    width:40px;
    height:40px;
    padding:0;

    border:1px solid #d9e3ee;
    border-radius:10px;

    background:#ffffff;
    color:#12233b;

    align-items:center;
    justify-content:center;

    box-shadow:0 5px 16px rgba(15,23,42,.12);
    cursor:pointer;
}

.ts-mobile-sidebar-toggle .ts-bars{
    width:18px;
    height:14px;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
}

.ts-mobile-sidebar-toggle .ts-bars span{
    display:block;
    width:100%;
    height:2px;
    border-radius:999px;
    background:currentColor;
    transition:transform .18s ease, opacity .18s ease;
}

.ts-mobile-sidebar-toggle.open .ts-bars span:nth-child(1){
    transform:translateY(6px) rotate(45deg);
}
.ts-mobile-sidebar-toggle.open .ts-bars span:nth-child(2){
    opacity:0;
}
.ts-mobile-sidebar-toggle.open .ts-bars span:nth-child(3){
    transform:translateY(-6px) rotate(-45deg);
}

/* One universal overlay */
.ts-sidebar-overlay{
    display:none;
    position:fixed;
    inset:0;
    z-index:1090;
    background:rgba(4,12,25,.48);
    backdrop-filter:blur(2px);
}

/* Desktop must remain absolutely constant */
@media(min-width:851px){
    .sidebar{
        transform:none!important;
        visibility:visible!important;
    }

    .ts-sidebar-overlay,
    .ts-mobile-sidebar-toggle{
        display:none!important;
    }
}

/* Mobile/tablet drawer */
@media(max-width:850px){
    .ts-mobile-sidebar-toggle{
        display:flex!important;
    }

    .sidebar{
        transform:translateX(-100%)!important;
        visibility:visible!important;
        transition:transform .18s ease!important;
        box-shadow:12px 0 32px rgba(5,14,31,.24)!important;
    }

    .sidebar.ts-sidebar-open,
    .sidebar.open{
        transform:translateX(0)!important;
    }

    .ts-sidebar-overlay.ts-sidebar-open{
        display:block!important;
    }

    body.ts-sidebar-lock{
        overflow:hidden!important;
    }

    /* Give top area room for hamburger without changing content width */
    .topbar,
    .header,
    .admin-header{
        padding-left:60px!important;
    }
}

/* Small phones */
@media(max-width:520px){
    .ts-mobile-sidebar-toggle{
        top:9px;
        left:9px;
        width:38px;
        height:38px;
    }

    .topbar,
    .header,
    .admin-header{
        padding-left:55px!important;
    }
}


/* ============================================================
   TRAVSCOPE SINGLE CLEAN PANEL
   Exactly one desktop sidebar and one mobile hamburger.
============================================================ */

.sidebar-brand-text{
    display:flex;
    flex-direction:column;
    gap:2px;
    min-width:0;
}
.sidebar-brand-text strong{
    color:#fff;
    font-size:13px;
    line-height:1;
    font-weight:800;
}
.sidebar-brand-text small{
    color:#8fa8c7;
    font-size:8px;
    line-height:1.1;
    font-weight:700;
    white-space:nowrap;
}

.sidebar{
    width:230px!important;
    min-width:230px!important;
    max-width:230px!important;
    position:fixed!important;
    left:0!important;
    top:0!important;
    bottom:0!important;
    z-index:1100!important;
    overflow:hidden!important;
}

.sidebar-menu{
    overflow-y:auto!important;
    overflow-x:hidden!important;
    scrollbar-width:none!important;
}
.sidebar-menu::-webkit-scrollbar{display:none!important}

.main,
.admin-main,
.odisha-style-main{
    margin-left:230px!important;
    width:calc(100% - 230px)!important;
    max-width:calc(100% - 230px)!important;
    min-width:0!important;
}

body > .page,
body > .content{
    margin-left:230px!important;
    width:calc(100% - 230px)!important;
    max-width:calc(100% - 230px)!important;
}

body > .header,
body > header.header,
body > .topbar,
body > header.topbar,
body > .admin-header,
body > header.admin-header{
    margin-left:230px!important;
    width:calc(100% - 230px)!important;
    max-width:calc(100% - 230px)!important;
}

.main > .topbar,
.main > .header,
.admin-main > .topbar,
.admin-main > .header,
.odisha-style-main > .topbar,
.odisha-style-main > .header{
    margin-left:0!important;
    width:100%!important;
    max-width:100%!important;
}

.ts-mobile-sidebar-toggle{
    display:none!important;
}

.ts-sidebar-overlay{
    display:none!important;
}

@media(max-width:850px){
    .sidebar{
        transform:translateX(-100%)!important;
        transition:transform .18s ease!important;
        width:230px!important;
        min-width:230px!important;
        max-width:230px!important;
    }

    .sidebar.ts-sidebar-open{
        transform:translateX(0)!important;
    }

    .ts-mobile-sidebar-toggle{
        display:flex!important;
        position:fixed!important;
        top:10px!important;
        left:10px!important;
        z-index:1305!important;
        width:40px!important;
        height:40px!important;
        padding:0!important;
        align-items:center!important;
        justify-content:center!important;
        border:1px solid #d9e3ee!important;
        border-radius:10px!important;
        background:#fff!important;
        color:#12233b!important;
        box-shadow:0 5px 16px rgba(15,23,42,.12)!important;
    }

    .ts-mobile-sidebar-toggle .ts-bars{
        width:18px!important;
        height:14px!important;
        display:flex!important;
        flex-direction:column!important;
        justify-content:space-between!important;
    }

    .ts-mobile-sidebar-toggle .ts-bars span{
        display:block!important;
        width:100%!important;
        height:2px!important;
        border-radius:999px!important;
        background:currentColor!important;
    }

    .ts-sidebar-overlay.ts-sidebar-open{
        display:block!important;
        position:fixed!important;
        inset:0!important;
        z-index:1090!important;
        background:rgba(4,12,25,.48)!important;
    }

    .main,
    .admin-main,
    .odisha-style-main,
    body > .page,
    body > .content,
    body > .header,
    body > header.header,
    body > .topbar,
    body > header.topbar,
    body > .admin-header,
    body > header.admin-header{
        margin-left:0!important;
        width:100%!important;
        max-width:100%!important;
    }

    body > .header,
    body > header.header,
    body > .topbar,
    body > header.topbar,
    body > .admin-header,
    body > header.admin-header{
        padding-left:58px!important;
    }
}


/* =========================================================
   SIMPLE CRM MANAGEMENT VIEW — Phase 0A UI refinement
   Visual/navigation only. Existing CRM actions remain intact.
========================================================= */
.topbar{display:none!important;}
.page{margin-top:12px!important;}
.crm-page-head-workspace{display:none!important;}
.crm-page-head-home{align-items:center!important;margin-bottom:12px!important;padding:14px 16px!important;border:1px solid #dbeafe!important;border-radius:14px!important;background:linear-gradient(135deg,#ffffff 0%,#eff6ff 100%)!important;box-shadow:0 8px 24px rgba(15,23,42,.05)!important;}
.crm-page-head-home h1{font-size:23px!important;margin:2px 0!important;}
.crm-page-head-home p{font-size:12px!important;margin:2px 0 0!important;}
.head-actions .button{min-height:38px!important;border-radius:10px!important;padding:0 14px!important;box-shadow:0 6px 14px rgba(15,23,42,.10)!important;}
.head-actions .button.blue{background:linear-gradient(135deg,#2563eb,#3b82f6)!important;}
.head-actions .button.green{background:linear-gradient(135deg,#059669,#10b981)!important;}

/* Trip card becomes a compact command bar */
.card[style*="linear-gradient(135deg,#eff6ff"]{border-radius:14px!important;box-shadow:0 8px 22px rgba(37,99,235,.07)!important;margin-bottom:10px!important;}
.card[style*="linear-gradient(135deg,#eff6ff"] .card-body{padding:13px 14px!important;}
.card[style*="linear-gradient(135deg,#eff6ff"] h2{font-size:21px!important;}
.card[style*="linear-gradient(135deg,#eff6ff"] form{margin-top:10px!important;grid-template-columns:1.05fr 1.05fr .9fr .72fr 1.2fr 1fr auto!important;gap:8px!important;}
.card[style*="linear-gradient(135deg,#eff6ff"] .control{min-height:36px!important;}
.card[style*="linear-gradient(135deg,#eff6ff"] .button{min-height:36px!important;border-radius:9px!important;white-space:nowrap!important;}

/* Clear, colourful workspace navigation */
.crm-workspace-tabs{position:sticky!important;top:6px!important;border-radius:12px!important;padding:6px!important;gap:7px!important;box-shadow:0 8px 22px rgba(15,23,42,.08)!important;}
.crm-workspace-tab{min-height:38px!important;padding:0 14px!important;border-radius:9px!important;font-size:11px!important;border:1px solid transparent!important;}
.crm-workspace-tab[data-crm-tab="overview"]{background:#eff6ff!important;color:#1d4ed8!important;border-color:#bfdbfe!important;}
.crm-workspace-tab[data-crm-tab="costing"]{background:#fff7ed!important;color:#c2410c!important;border-color:#fed7aa!important;}
.crm-workspace-tab[data-crm-tab="package"]{background:#f5f3ff!important;color:#6d28d9!important;border-color:#ddd6fe!important;}
.crm-workspace-tab[data-crm-tab="activities"]{background:#ecfdf5!important;color:#047857!important;border-color:#a7f3d0!important;}
.crm-workspace-tab.active{box-shadow:inset 0 0 0 2px currentColor,0 4px 10px rgba(15,23,42,.08)!important;font-weight:800!important;}
.contact.quotation{background:linear-gradient(135deg,#7c3aed,#8b5cf6)!important;color:#fff!important;border-color:#7c3aed!important;}
.contact.call{background:#eff6ff!important;color:#1d4ed8!important;border-color:#bfdbfe!important;}
.contact.whatsapp{background:#ecfdf5!important;color:#047857!important;border-color:#a7f3d0!important;}
.contact.email{background:#fff7ed!important;color:#c2410c!important;border-color:#fed7aa!important;}

/* Pipeline is the only CRM management list */
.crm-pipeline-heading{display:flex;align-items:end;justify-content:space-between;margin:4px 0 8px;padding:11px 13px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;}
.crm-pipeline-heading h2{margin:2px 0;font-size:18px;color:#0f172a;}
.crm-pipeline-heading p{margin:0;color:#64748b;font-size:11px;}
.filters{margin-bottom:8px!important;padding:8px!important;border-radius:11px!important;background:#fff!important;box-shadow:none!important;}
.crm-single-view{margin:0 0 8px!important;}
.crm-pipeline-pill{display:inline-flex;align-items:center;gap:7px;min-height:34px;padding:0 12px;border-radius:9px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:#fff;font-size:11px;font-weight:800;}
.pipeline{grid-template-columns:repeat(7,minmax(220px,1fr))!important;gap:9px!important;padding:2px 2px 10px!important;}
.pipe-column{min-height:230px!important;background:#f8fafc!important;border-radius:12px!important;overflow:hidden!important;box-shadow:0 5px 14px rgba(15,23,42,.04)!important;}
.pipe-column:nth-child(1){border-top:4px solid #3b82f6!important}.pipe-column:nth-child(2){border-top:4px solid #06b6d4!important}.pipe-column:nth-child(3){border-top:4px solid #8b5cf6!important}.pipe-column:nth-child(4){border-top:4px solid #f59e0b!important}.pipe-column:nth-child(5){border-top:4px solid #ec4899!important}.pipe-column:nth-child(6){border-top:4px solid #10b981!important}.pipe-column:nth-child(7){border-top:4px solid #64748b!important}
.pipe-head{background:#fff!important;font-size:11px!important;padding:9px 10px!important;}
.pipe-head span:last-child{display:inline-flex;align-items:center;justify-content:center;min-width:23px;height:23px;border-radius:999px;background:#e2e8f0;color:#334155;font-weight:900;}
.lead-card{border:1px solid #e2e8f0!important;border-radius:10px!important;background:#fff!important;box-shadow:0 3px 9px rgba(15,23,42,.04)!important;transition:.15s ease!important;}
.lead-card:hover{transform:translateY(-2px)!important;box-shadow:0 8px 18px rgba(15,23,42,.09)!important;border-color:#bfdbfe!important;}
.lead-card .action.manage{min-height:30px!important;border-radius:8px!important;background:linear-gradient(135deg,#2563eb,#3b82f6)!important;}

/* Selected Trip/Quotation workspace should feel isolated: no unrelated leads below. */
.crm-workspace-panel{padding-bottom:8px!important;}

@media(max-width:1250px){
  .card[style*="linear-gradient(135deg,#eff6ff"] form{grid-template-columns:repeat(3,minmax(0,1fr))!important;}
}
@media(max-width:760px){
  .crm-page-head-home{align-items:flex-start!important;flex-direction:column!important;}
  .crm-workspace-tabs{overflow-x:auto!important;justify-content:flex-start!important;}
  .card[style*="linear-gradient(135deg,#eff6ff"] form{grid-template-columns:1fr!important;}
  .pipeline{grid-template-columns:repeat(7,minmax(200px,1fr))!important;}
}


/* Phase 0B Services + Cost Sheet */
.ts-costing-workspace{border-color:#fed7aa!important}.ts-cost-layout{display:grid;grid-template-columns:1fr 1fr;gap:16px}.ts-cost-column{border:1px solid #e2e8f0;border-radius:15px;padding:14px;background:#fff}.ts-cost-title{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}.ts-cost-title h3{margin:0;font-size:15px}.ts-cost-title span{font-size:9px;font-weight:900;background:#fff7ed;color:#c2410c;padding:5px 8px;border-radius:999px}.ts-service-list,.ts-cost-items{display:grid;gap:8px}.ts-service-card,.ts-cost-item{display:flex;justify-content:space-between;gap:10px;border:1px solid #e2e8f0;border-radius:11px;padding:10px;background:#fbfdff}.ts-service-card strong,.ts-cost-item strong{font-size:11px;color:#0f172a}.ts-service-card small,.ts-cost-item small{display:block;margin-top:3px;font-size:9px;color:#64748b}.ts-service-card p{margin:6px 0 0;font-size:9px;color:#475569;line-height:1.5}.ts-trash{border:0;background:#fff1f2;color:#be123c;width:29px;height:29px;border-radius:8px;cursor:pointer}.ts-add-box{margin-top:10px;border:1px dashed #cbd5e1;border-radius:11px;background:#f8fafc}.ts-add-box summary{cursor:pointer;padding:10px 11px;font-size:10px;font-weight:900;list-style:none}.ts-form{padding:0 11px 11px;display:grid;grid-template-columns:1fr 1fr;gap:9px}.ts-form .full{grid-column:1/-1}.ts-cost-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-bottom:10px}.ts-cost-summary>div{border:1px solid #e2e8f0;border-radius:11px;padding:9px;background:#f8fafc}.ts-cost-summary span{display:block;font-size:8px;font-weight:900;text-transform:uppercase;color:#64748b}.ts-cost-summary strong{display:block;margin-top:4px;font-size:13px;color:#0f172a}.ts-cost-summary .total{background:#ecfdf5;border-color:#a7f3d0}.ts-cost-summary .total strong{color:#047857}.ts-actions{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:10px}.ts-cost-items{max-height:390px;overflow:auto}.ts-cost-item b{font-size:11px;white-space:nowrap}.ts-cost-empty{min-height:130px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:5px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#64748b;padding:14px}.ts-cost-empty strong{color:#334155;font-size:12px}.ts-cost-empty span{font-size:9px}.ts-cost-empty.small{min-height:90px}.ts-flow{margin-top:10px;padding:8px 9px;border-radius:10px;background:#0f172a;color:#cbd5e1;font-size:9px;font-weight:800;display:flex;align-items:center;gap:6px;flex-wrap:wrap}.ts-flow strong{color:#86efac}.ts-flow i{color:#60a5fa}@media(max-width:1000px){.ts-cost-layout{grid-template-columns:1fr}}@media(max-width:650px){.ts-form,.ts-cost-summary{grid-template-columns:1fr}.ts-form .full{grid-column:auto}}


/* =========================================================
   V24 QUOTATION DESK — clear enquiry-to-quotation workflow
========================================================= */
.qdesk-shell{margin:0 0 14px;border:1px solid #bfdbfe;border-radius:15px;background:linear-gradient(135deg,#f8fbff 0%,#ffffff 55%,#f5f3ff 100%);padding:12px;box-shadow:0 10px 26px rgba(30,64,175,.06)}
.qdesk-flow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;margin-bottom:10px}.qdesk-step{min-width:0;display:flex;align-items:center;gap:8px;text-align:left;border:1px solid #dbeafe;background:#fff;border-radius:11px;padding:8px 9px;cursor:pointer;color:#334155}.qdesk-step b{width:25px;height:25px;border-radius:8px;display:grid;place-items:center;background:#e2e8f0;color:#334155;font-size:10px;flex:0 0 auto}.qdesk-step span{font-size:9px;font-weight:900;line-height:1.25}.qdesk-step small{display:block;margin-top:2px;font-size:7px;color:#64748b;font-weight:700}.qdesk-step.is-done{border-color:#a7f3d0;background:#f0fdf4;color:#047857}.qdesk-step.is-done b{background:#10b981;color:#fff}.qdesk-step:hover{border-color:#93c5fd;transform:translateY(-1px)}
.qdesk-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}.qdesk-card{min-width:0;border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:10px}.qdesk-customer{border-top:3px solid #2563eb}.qdesk-package{border-top:3px solid #7c3aed}.qdesk-supplier{border-top:3px solid #f59e0b}.qdesk-selling{border-top:3px solid #10b981}.qdesk-card-head{display:flex;align-items:center;justify-content:space-between;gap:7px;margin-bottom:7px}.qdesk-card-head>span{font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:.3px;color:#64748b}.qdesk-card-head button,.qdesk-card-head a{border:0;background:#eff6ff;color:#1d4ed8;border-radius:7px;padding:4px 6px;font-size:7px;font-weight:900;text-decoration:none;cursor:pointer}.qdesk-card>strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12px;color:#0f172a;margin-bottom:7px}.qdesk-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px}.qdesk-facts span{min-width:0;padding:5px 6px;border-radius:8px;background:#f8fafc}.qdesk-facts small{display:block;font-size:6.5px;color:#64748b;text-transform:uppercase;font-weight:900}.qdesk-facts b{display:block;margin-top:2px;font-size:8px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.qdesk-money{display:flex;align-items:end;justify-content:space-between;gap:8px;padding:6px 7px;border-radius:8px;background:#fff7ed}.qdesk-money small{font-size:7px;color:#9a3412;font-weight:900}.qdesk-money b{font-size:12px;color:#9a3412}.qdesk-service-pills{display:flex;gap:4px;flex-wrap:wrap;margin-top:6px}.qdesk-service-pills span{padding:3px 5px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:6.5px;font-weight:800}.qdesk-selling>strong{font-size:18px;color:#047857}.qdesk-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:9px;padding-top:9px;border-top:1px solid #e2e8f0}.qdesk-actions>span{font-size:8px;color:#64748b;line-height:1.4}.qdesk-actions>div{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.qdesk-actions .button{min-height:30px!important;padding:0 9px!important;font-size:8px!important;border-radius:8px!important}.package-studio-card .studio-layout{grid-template-columns:minmax(0,1fr) 355px!important;gap:12px!important}.package-studio-card .studio-toolbar{position:static;top:auto;z-index:auto;background:linear-gradient(135deg,#f8fbff,#f3f7ff);backdrop-filter:none;border-color:#d7e4f5;box-shadow:0 5px 16px rgba(15,23,42,.04)}.package-studio-card .studio-section>summary{font-size:11px}.package-studio-card .version-panel{top:58px!important}.package-studio-card .version-panel-head{background:linear-gradient(135deg,#0f172a,#1e3a8a)!important;color:#fff}.package-studio-card .version-panel-head span,.package-studio-card .version-panel-head h3{color:#fff!important}.studio-save-bar{border-color:#86efac!important;background:rgba(240,253,244,.97)!important}.studio-save-button{background:linear-gradient(135deg,#059669,#10b981)!important;border-color:#059669!important}.crm-workspace-tab[data-crm-tab="package"].active{background:#ede9fe!important;color:#5b21b6!important}.crm-workspace-tab{white-space:nowrap!important}
@media(max-width:1320px){.qdesk-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.qdesk-flow{grid-template-columns:repeat(5,minmax(150px,1fr));overflow-x:auto;padding-bottom:3px}.package-studio-card .studio-layout{grid-template-columns:1fr!important}.package-studio-card .version-panel{position:static!important}.package-studio-card .studio-toolbar{position:static!important}}
@media(max-width:760px){.qdesk-grid{grid-template-columns:1fr}.qdesk-actions{align-items:stretch;flex-direction:column}.qdesk-actions>div{justify-content:flex-start}.qdesk-facts{grid-template-columns:1fr 1fr}}

</style>

<!-- TRAVSCOPE PRO UNIFORM UI -->
<script>document.documentElement.classList.add('ts-pro-preload');</script>
<link rel="stylesheet" href="assets/travscope-pro-admin.css?v=20260916-v2">

<style id="crm-international-v2458">
/* TRAVSCOPE V24.58 — home-only styles; leave the lead/trip/quotation workspace intact. */
.crm-sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.crm-home-v2458{max-width:none!important;width:calc(100% - 28px)!important;margin:12px 14px 25px!important;padding:0!important;height:auto!important;overflow:visible!important;min-width:0}
.crm-home-v2458 .crm-page-head-home{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;align-items:center!important;column-gap:16px!important;row-gap:10px!important;margin:0 0 11px!important;padding:14px 16px 13px!important;border:1px solid #dbe6f5!important;border-left:4px solid #2563eb!important;border-radius:14px!important;background:linear-gradient(120deg,#fff 0%,#f4f9ff 78%,#edf6ff 100%)!important;box-shadow:0 7px 20px rgba(15,23,42,.04)!important}
.crm-home-v2458 .crm-page-head-home .eyebrow{margin:0!important;font-size:9px!important;letter-spacing:1.2px!important;color:#2563eb!important;font-family:inherit!important;font-weight:900!important}
.crm-home-v2458 .crm-page-head-home h1{font-family:inherit!important;font-size:23px!important;font-weight:850!important;letter-spacing:-.5px!important;color:#0f172a!important}
.crm-home-v2458 .crm-page-head-home p{font-size:11px!important;margin-top:3px!important;line-height:1.4!important}
.crm-home-v2458 .head-actions{align-items:center!important}
.crm-home-v2458 .head-actions form{margin:0!important}
.crm-home-v2458 .head-actions .button{min-height:35px!important;border-radius:9px!important;padding:0 12px!important;box-shadow:none!important}
.crm-home-metrics{grid-column:1/-1;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;min-width:0;padding-top:9px;border-top:1px solid #e1eaf6}
.crm-home-metric{display:flex;align-items:center;gap:9px;min-width:0;min-height:51px;padding:7px 10px;border:1px solid #dbeafe;border-left:3px solid #2563eb;border-radius:9px;background:rgba(255,255,255,.88)}
.crm-home-metric .crm-metric-icon{display:grid;place-items:center;flex:0 0 28px;width:28px;height:28px;border-radius:8px;background:#dbeafe;color:#1d4ed8;font-size:12px}
.crm-home-metric div{min-width:0;display:flex;align-items:baseline;justify-content:space-between;gap:5px;flex-wrap:wrap;flex:1}
.crm-home-metric div>span{font-size:10px;font-weight:750;color:#475569;line-height:1.3}
.crm-home-metric strong{font-size:17px;line-height:1.1;font-weight:850;color:#0f172a;white-space:nowrap;font-family:inherit}
.crm-home-metric:nth-child(2){border-left-color:#10b981;border-color:#d1fae5;border-left-color:#10b981}
.crm-home-metric:nth-child(2) .crm-metric-icon{background:#d1fae5;color:#047857}
.crm-home-metric:nth-child(3){border-color:#fef3c7;border-left-color:#f59e0b}
.crm-home-metric:nth-child(3) .crm-metric-icon{background:#fef3c7;color:#b45309}
.crm-home-metric:nth-child(4){border-color:#ede9fe;border-left-color:#8b5cf6}
.crm-home-metric:nth-child(4) .crm-metric-icon{background:#ede9fe;color:#6d28d9}
.crm-home-metric:nth-child(5){border-color:#ccfbf1;border-left-color:#0d9488}
.crm-home-metric:nth-child(5) .crm-metric-icon{background:#ccfbf1;color:#0f766e}
.crm-home-v2458 .crm-pipeline-heading{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:12px!important;min-height:59px!important;margin:0!important;padding:10px 13px!important;border:1px solid #dbe6f2!important;border-radius:12px 12px 0 0!important;background:linear-gradient(105deg,#f8fbff,#fff)!important;box-shadow:none!important}
.crm-home-v2458 .crm-pipeline-heading .eyebrow{font-size:9px!important;font-weight:900!important;line-height:1.1!important;color:#2563eb!important;letter-spacing:1px!important;margin:0!important}
.crm-home-v2458 .crm-pipeline-heading h2{margin:3px 0 0!important;font-size:17px!important;font-weight:850!important;line-height:1.2!important;color:#0f172a!important}
.crm-home-v2458 .crm-pipeline-heading p{font-size:10px!important;font-weight:400!important;line-height:1.4!important;color:#64748b!important;margin:2px 0 0!important}
.crm-source-ribbon{display:flex;align-items:center;justify-content:flex-end;gap:6px;max-width:65%;min-width:0;overflow-x:auto;padding:1px 1px 4px}
.crm-source-heading{font-size:9px;font-weight:900;letter-spacing:.7px;color:#64748b;white-space:nowrap}
.crm-source-chip{display:inline-flex;align-items:center;gap:6px;flex:0 0 auto;border:1px solid #dbeafe;border-radius:8px;padding:6px 9px;background:#eff6ff;color:#1d4ed8;font-size:10px;font-weight:800}
.crm-source-chip strong{color:#0f172a;font-size:12px}
.crm-source-chip small{padding-left:5px;border-left:1px solid #bfdbfe;font-size:9px;color:#64748b;font-weight:600}
.crm-pipeline-directory{border:1px solid #dbe6f2;border-top:0;border-radius:0 0 12px 12px;background:#fff;padding:10px;min-width:0;box-shadow:0 6px 20px rgba(15,23,42,.035)}
.crm-home-v2458 .crm-pipeline-filters{display:flex!important;flex-wrap:wrap!important;align-items:center!important;gap:7px!important;padding:0 0 9px!important;margin:0!important;border:0!important;border-bottom:1px solid #e2e8f0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}
.crm-home-v2458 .crm-pipeline-filters .control{flex:1 1 155px;min-width:0;width:auto!important;min-height:35px!important;border:1px solid #d3dfed!important;border-radius:8px!important;background:#fff!important;font-size:11px!important;font-weight:500!important;line-height:1.3!important;padding:0 9px!important;color:#334155!important}
.crm-home-v2458 .crm-pipeline-filters input.control{flex:3 1 290px}
.crm-home-v2458 .crm-pipeline-filters .button{min-height:35px!important;border-radius:8px!important;padding:0 12px!important;flex:0 0 auto}
.crm-clear-filter{display:inline-flex;align-items:center;gap:5px;white-space:nowrap;padding:0 9px;height:35px;border:1px solid #dbe6f2;border-radius:8px;background:#f8fafc;color:#475569;font-size:10px;font-weight:800;text-decoration:none}
.crm-clear-filter:hover{background:#eff6ff;color:#1d4ed8}
.crm-board-toolbar{display:flex;align-items:center;justify-content:space-between;gap:9px;padding:9px 1px 8px}
.crm-home-v2458 .crm-pipeline-pill{min-height:27px!important;padding:0 10px!important;font-size:10px!important;border-radius:7px!important;background:#153c76!important}
.crm-board-count{color:#64748b;font-size:10px}
.crm-board-count strong{color:#0f172a;font-size:12px}
.crm-home-v2458 .pipeline{display:grid!important;grid-template-columns:repeat(7,minmax(168px,1fr))!important;gap:8px!important;overflow-x:auto!important;align-items:start!important;padding:0 0 8px!important;margin:0!important;min-width:0!important}
.crm-home-v2458 .pipe-column{min-width:0!important;min-height:160px!important;border:1px solid #dae5f0!important;border-top:3px solid #3b82f6!important;border-radius:9px!important;background:#f8fafc!important;box-shadow:none!important;overflow:hidden!important}
.crm-home-v2458 .pipe-column:nth-child(2){border-top-color:#06b6d4!important}.crm-home-v2458 .pipe-column:nth-child(3){border-top-color:#8b5cf6!important}.crm-home-v2458 .pipe-column:nth-child(4){border-top-color:#f59e0b!important}.crm-home-v2458 .pipe-column:nth-child(5){border-top-color:#ec4899!important}.crm-home-v2458 .pipe-column:nth-child(6){border-top-color:#10b981!important}.crm-home-v2458 .pipe-column:nth-child(7){border-top-color:#64748b!important}
.crm-home-v2458 .pipe-head{min-height:37px!important;padding:7px 8px!important;background:#fff!important;border-bottom:1px solid #e2e8f0!important;font-size:10px!important;color:#25354a!important;gap:5px!important}
.crm-home-v2458 .pipe-head span:last-child{min-width:21px!important;height:21px!important;background:#eaf2ff!important;color:#1d4ed8!important}
.crm-home-v2458 .pipe-column:nth-child(6) .pipe-head span:last-child{background:#d1fae5!important;color:#047857!important}
.crm-home-v2458 .pipe-body{padding:7px!important;min-height:92px!important}
.crm-home-v2458 .crm-empty-stage{text-align:center;margin:14px 1px!important;color:#94a3b8;font-size:10px!important;line-height:1.5!important}
.crm-home-v2458 .crm-empty-stage i{display:block;font-size:16px;margin-bottom:5px;color:#b8c7d7}
.crm-home-v2458 .lead-card{margin:0 0 7px!important;padding:9px!important;border:1px solid #dbe5ee!important;border-radius:8px!important;background:#fff!important;box-shadow:0 2px 7px rgba(15,23,42,.03)!important;min-width:0!important;overflow-wrap:anywhere}
.crm-home-v2458 .lead-card:hover{transform:translateY(-1px)!important;border-color:#93c5fd!important;box-shadow:0 5px 11px rgba(37,99,235,.09)!important}
.crm-lead-top{display:flex;align-items:start;justify-content:space-between;gap:5px}
.crm-home-v2458 .crm-lead-top h3{font-size:11px!important;font-weight:850!important;line-height:1.4!important;color:#0f2b52!important;margin:0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.crm-lead-top small{white-space:nowrap;font-size:8px;color:#94a3b8}
.crm-home-v2458 .crm-lead-source{display:block!important;font-size:9px!important;line-height:1.4!important;margin:5px 0!important;color:#64748b!important;overflow-wrap:anywhere}
.crm-home-v2458 .crm-lead-facts{display:flex;align-items:center;justify-content:space-between;gap:4px;margin-top:6px}
.crm-home-v2458 .crm-lead-facts strong{font-size:10px!important;font-weight:800!important;line-height:1.3!important;color:#0f172a!important;overflow-wrap:anywhere}
.crm-home-v2458 .crm-lead-facts span{font-size:9px;color:#64748b;white-space:nowrap}
.crm-home-v2458 .crm-lead-follow{font-size:9px!important;line-height:1.4!important;padding:5px 0!important;margin:5px 0 0!important;color:#a16207!important;border-top:1px solid #f1f5f9}
.crm-home-v2458 .lead-card-bottom{margin-top:7px!important;gap:4px!important}
.crm-home-v2458 .lead-card-bottom .badge{font-size:9px!important;padding:4px 6px!important}
.crm-home-v2458 .lead-card-bottom .action.manage{min-height:27px!important;padding:0 9px!important;border-radius:7px!important;font-size:9px!important;background:#2563eb!important;color:#fff!important;text-decoration:none}
.crm-home-v2458 .lead-card-bottom .action.manage:hover{background:#1d4ed8!important}
@media(max-width:1480px){.crm-home-v2458 .pipeline{grid-template-columns:repeat(7,minmax(185px,1fr))!important}}
@media(max-width:1120px){.crm-home-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.crm-home-v2458 .crm-pipeline-heading{align-items:flex-start!important;flex-direction:column!important}.crm-source-ribbon{max-width:100%;justify-content:flex-start}}
@media(max-width:740px){.crm-home-v2458{width:calc(100% - 20px)!important;margin:10px!important}.crm-home-v2458 .crm-page-head-home{grid-template-columns:1fr!important;padding:12px!important}.crm-home-v2458 .head-actions{justify-content:flex-start!important}.crm-home-metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}.crm-home-metric{padding:7px;gap:6px}.crm-home-metric strong{font-size:14px}.crm-home-v2458 .crm-pipeline-filters .control{flex:1 1 calc(50% - 7px)!important}.crm-home-v2458 .crm-pipeline-filters input.control{flex:1 1 100%!important}.crm-home-v2458 .pipeline{grid-template-columns:repeat(7,minmax(190px,1fr))!important}.crm-board-cap{display:none}}
</style>
<link rel="stylesheet" href="assets/crm-itinerary-studio-v2497.css?v=2497">

<!-- TRAVSCOPE V24.99 responsive screen foundation -->
<link rel="stylesheet" href="assets/travscope-responsive-core-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-responsive-admin-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-experience-v2500.css?v=2500" media="screen">
<script defer src="assets/travscope-responsive-v2499.js?v=2499"></script>
<script defer src="assets/travscope-experience-v2500.js?v=2500" data-ts25-area="admin"></script>
</head>
<body>
<button type="button"
        class="ts-mobile-sidebar-toggle"
        id="tsMobileSidebarToggle"
        aria-label="Open admin menu"
        aria-controls="sidebar"
        aria-expanded="false">
    <span class="ts-bars" aria-hidden="true">
        <span></span><span></span><span></span>
    </span>
</button>
<div class="ts-sidebar-overlay" id="tsSidebarOverlay" aria-hidden="true"></div>
<!-- =========================================================
     TRAVSCOPE SINGLE CONSTANT ADMIN SIDEBAR
========================================================= -->
<aside class="sidebar" id="sidebar">
    <a href="admin-dashboard.php" class="sidebar-logo">
        <div class="logo-icon"><i class="fa-solid fa-plane"></i></div>
        <div class="sidebar-brand-text">
            <strong>TRAVSCOPE</strong>
            <small>Travel Business System</small>
        </div>
    </a>

    <nav class="sidebar-menu">
        <div class="menu-title">OVERVIEW</div>

        <a href="admin-dashboard.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-dashboard.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
        </a>

        <div class="menu-title">SALES & CRM</div>

        <a href="admin-enquiries.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-enquiries.php','admin-enquiry-details.php','admin-crm.php'], true) ? 'active' : ''; ?>">
            <i class="fa-regular fa-message"></i><span>Enquiries</span>
        </a>

        <a href="admin-customers.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-customers.php','admin-users.php','admin-user-details.php'], true) ? 'active' : ''; ?>">
            <i class="fa-solid fa-users"></i><span>Customers</span>
        </a>
<div class="menu-title">TRAVEL PRODUCTS</div>

        <a href="admin-destinations.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-destinations.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-earth-americas"></i><span>Destinations</span>
        </a>

        <a href="admin-packages.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-packages.php','admin-package-images.php'], true) ? 'active' : ''; ?>">
            <i class="fa-solid fa-suitcase-rolling"></i><span>Packages</span>
        </a>


        <div class="menu-title">SUPPLIERS</div>

        <a href="admin-suppliers.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-suppliers.php','admin-supplier-details.php'], true) ? 'active' : ''; ?>">
            <i class="fa-solid fa-handshake"></i><span>Suppliers</span>
        </a>

        <div class="menu-title">OPERATIONS</div>

        <a href="admin-bookings.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-bookings.php','admin-booking-details.php'], true) ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-check"></i><span>Bookings</span>
        </a>

        <a href="admin-cancellations.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-cancellations.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-ban"></i><span>Cancellations</span>
        </a>

        <div class="menu-title">FINANCE</div>

        <a href="admin-payments.php"
           class="menu-link <?= in_array(basename($_SERVER['PHP_SELF']), ['admin-payments.php','admin-payment-details.php'], true) ? 'active' : ''; ?>">
            <i class="fa-solid fa-credit-card"></i><span>Payments</span>
        </a>

        <div class="menu-title">MARKETING</div>

        <a href="admin-coupons.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-coupons.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-ticket"></i><span>Coupons</span>
        </a>

        <a href="admin-reviews.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-reviews.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-star"></i><span>Reviews</span>
        </a>

        <div class="menu-title">CONTENT</div>

        <a href="admin-blogs.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-blogs.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-pen-nib"></i><span>Travel Stories</span>
        </a>

        <a href="admin-web-gallery.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-web-gallery.php' ? 'active' : ''; ?>">
            <i class="fa-regular fa-images"></i><span>Media Library</span>
        </a>

        <div class="menu-title">WEBSITE</div>

        <a href="admin-pages.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-pages.php' ? 'active' : ''; ?>">
            <i class="fa-regular fa-file-lines"></i><span>Website Content</span>
        </a>

        <a href="<?= defined('BASE_URL') ? e(BASE_URL) : '/'; ?>" target="_blank" class="menu-link">
            <i class="fa-solid fa-globe"></i><span>View Website</span>
        </a>

        <div class="menu-title">ADMINISTRATION</div>

        <a href="admin-settings.php"
           class="menu-link <?= basename($_SERVER['PHP_SELF']) === 'admin-settings.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-gear"></i><span>Settings</span>
        </a>

        <a href="logout.php" class="menu-link">
            <i class="fa-solid fa-arrow-right-from-bracket"></i><span>Logout</span>
        </a>
    </nav>
</aside>

<div class="odisha-style-admin-layout">

<!-- =========================================================
     CONSTANT TRAVSCOPE ADMIN SIDEBAR
========================================================= -->



<section class="odisha-style-main">

<header class="topbar">
    <div class="odisha-style-topbar-title">
        TRAVSCOPE Professional CRM
    </div>

    <div class="odisha-style-topbar-right">
        Customer Relationship Management
    </div>
</header>

<main class="page <?= !$selectedLead ? 'crm-home-v2458' : ''; ?>">
    <div class="page-head <?= $selectedLead ? 'crm-page-head-workspace' : 'crm-page-head-home'; ?>">
        <div>
            <div class="eyebrow">CUSTOMER RELATIONSHIP MANAGEMENT</div>
            <h1>Professional Travel CRM</h1>
            <p>Website, social media, campaign, follow-up and conversion management in one place.</p>
        </div>
        <div class="head-actions">
            <button type="button" class="button blue" id="openLeadModal"><i class="fa-solid fa-plus"></i> Add New Lead</button>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                <input type="hidden" name="action" value="export_csv">
                <button class="button green" type="submit"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
            </form>
        </div>
        <?php if ($crmReady && !$selectedLead): ?>
        <div class="crm-home-metrics" aria-label="CRM overview">
            <div class="crm-home-metric"><span class="crm-metric-icon"><i class="fa-solid fa-users"></i></span><div><span>Total leads</span><strong><?= number_format($stats['total']); ?></strong></div></div>
            <div class="crm-home-metric"><span class="crm-metric-icon"><i class="fa-solid fa-bolt"></i></span><div><span>New today</span><strong><?= number_format($stats['today']); ?></strong></div></div>
            <div class="crm-home-metric"><span class="crm-metric-icon"><i class="fa-regular fa-clock"></i></span><div><span>Overdue follow-ups</span><strong><?= number_format($stats['overdue']); ?></strong></div></div>
            <div class="crm-home-metric"><span class="crm-metric-icon"><i class="fa-solid fa-circle-check"></i></span><div><span>Converted</span><strong><?= number_format($stats['converted']); ?></strong></div></div>
            <div class="crm-home-metric"><span class="crm-metric-icon"><i class="fa-solid fa-indian-rupee-sign"></i></span><div><span>Converted value</span><strong><?= e(crmMoney($stats['value'], $currencySymbol)); ?></strong></div></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($success !== ''): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= e($success); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error); ?></div><?php endif; ?>

    <?php if (!$crmReady): ?>
        <section class="card setup">
            <i class="fa-solid fa-database" style="font-size:35px;color:var(--blue)"></i>
            <h2>Professional CRM SQL upgrade required</h2>
            <p>Import <strong>travscope-professional-crm-upgrade.sql</strong> into the same database configured in config.php, then refresh this page.</p>
        </section>
    <?php else: ?>

        <?php if ($selectedLead): ?>

            <section class="card" style="margin-bottom:16px;border:1px solid #dbeafe;background:linear-gradient(135deg,#eff6ff,#ffffff);">
                <div class="card-body">
                    <?php if ($selectedTrip): ?>
                        <div style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap;">
                            <div>
                                <div class="eyebrow">TRIP / OPPORTUNITY WORKSPACE</div>
                                <h2 style="margin:4px 0 5px;font-size:24px;"><?= e((string)$selectedTrip['trip_code']); ?></h2>
                                <div style="color:#64748b;font-size:13px;"><?= e((string)$selectedLead['name']); ?> · <?= e((string)($selectedLead['destination'] ?: 'Destination pending')); ?> · <?= e(crmDate($selectedLead['travel_date'])); ?> · <?= (int)$selectedLead['adults']; ?> Adult<?= (int)$selectedLead['adults'] === 1 ? '' : 's'; ?><?= (int)$selectedLead['children'] > 0 ? ' + ' . (int)$selectedLead['children'] . ' Children' : ''; ?></div>
                            </div>
                            <span class="badge" style="background:#dbeafe;color:#1d4ed8;"><?= e(crmTripStageLabel((string)$selectedTrip['stage'])); ?></span>
                        </div>
                        <form method="post" style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end;">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                            <input type="hidden" name="action" value="save_trip">
                            <input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>">
                            <input type="hidden" name="trip_id" value="<?= (int)$selectedTrip['id']; ?>">
                            <div class="field"><label>Trip Stage</label><select class="control" name="trip_stage"><?php foreach (crmTripStages() as $tripStage): ?><option value="<?= e($tripStage); ?>" <?= (string)$selectedTrip['stage'] === $tripStage ? 'selected' : ''; ?>><?= e(crmTripStageLabel($tripStage)); ?></option><?php endforeach; ?></select></div>
                            <div class="field"><label>Owner</label><select class="control" name="trip_owner_admin_id"><option value="0">Unassigned</option><?php foreach ($tripAdminOptions as $tripAdmin): ?><option value="<?= (int)$tripAdmin['id']; ?>" <?= (int)($selectedTrip['owner_admin_id'] ?? 0) === (int)$tripAdmin['id'] ? 'selected' : ''; ?>><?= e((string)$tripAdmin['name']); ?></option><?php endforeach; ?></select></div>
                            <div class="field"><label>Expected Value</label><input class="control" type="number" min="0" step="0.01" name="trip_expected_value" value="<?= e((string)$selectedTrip['expected_value']); ?>"></div>
                            <div class="field"><label>Probability %</label><input class="control" type="number" min="0" max="100" name="trip_probability" value="<?= (int)$selectedTrip['probability']; ?>"></div>
                            <div class="field"><label>Next Action</label><input class="control" name="trip_next_action" value="<?= e((string)($selectedTrip['next_action'] ?? '')); ?>" placeholder="Call customer, send revision..."></div>
                            <div class="field"><label>Next Action Date</label><input class="control" type="date" name="trip_next_action_date" value="<?= e((string)($selectedTrip['next_action_date'] ?? '')); ?>"></div>
                            <button class="button blue" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Trip</button>
                        </form>
                    <?php else: ?>
                        <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;">
                            <div><div class="eyebrow">TRIP / OPPORTUNITY</div><h2 style="margin:4px 0;">Create Trip Foundation</h2><p style="margin:0;color:#64748b;">Create one permanent Trip ID for this enquiry. Existing CRM data stays intact.</p></div>
                            <form method="post" style="margin:0;"><input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>"><input type="hidden" name="action" value="create_trip"><input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>"><button class="button green" type="submit"><i class="fa-solid fa-route"></i> Create Trip</button></form>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="crm-workspace-tabs" id="crmWorkspaceTabs">
                <button type="button" class="crm-workspace-tab active" data-crm-tab="overview">
                    <i class="fa-regular fa-address-card"></i>
                    1 · Enquiry
                </button>
                <button type="button" class="crm-workspace-tab" data-crm-tab="costing">
                    <i class="fa-solid fa-layer-group"></i>
                    2 · Supplier & Cost
                </button>
                <button type="button" class="crm-workspace-tab" data-crm-tab="package">
                    <i class="fa-solid fa-suitcase-rolling"></i>
                    3 · Quotation Builder
                </button>
                <button type="button" class="crm-workspace-tab" data-crm-tab="activities">
                    <i class="fa-regular fa-clock"></i>
                    4 · Follow-up
                </button>
            </div>

            <section class="details-grid crm-workspace-panel is-active" data-crm-panel="overview" id="crm-panel-overview">
                <div class="card">
                    <div class="card-head">
                        <h2>#ENQ<?= str_pad((string)$selectedLead['id'], 5, '0', STR_PAD_LEFT); ?> — <?= e($selectedLead['name']); ?></h2>
                        <span class="badge status-<?= e($selectedLead['status']); ?>"><?= e(crmStatusLabel($selectedLead['status'])); ?></span>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info"><label>Mobile</label><div><?= e($selectedLead['mobile'] ?: '-'); ?></div></div>
                            <div class="info"><label>Email</label><div><?= e($selectedLead['email'] ?: '-'); ?></div></div>
                            <div class="info"><label>Destination</label><div><?= e($selectedLead['destination'] ?: '-'); ?></div></div>
                            <div class="info"><label>Travel Date</label><div><?= e(crmDate($selectedLead['travel_date'])); ?></div></div>
                            <div class="info"><label>Source</label><div><i class="<?= e(crmSourceIcon($selectedLead['lead_source'])); ?>"></i> <?= e(crmSourceLabel($selectedLead['lead_source'])); ?></div></div>
                            <div class="info"><label>Lead Score</label><div><?= (int)$selectedLead['lead_score']; ?>/100 — <?= e(crmLeadScoreLabel((int)$selectedLead['lead_score'])); ?></div></div>
                            <div class="info"><label>Campaign</label><div><?= e($selectedLead['campaign_name'] ?: '-'); ?></div></div>
                            <div class="info"><label>Social Profile</label><div><?= e($selectedLead['social_profile_name'] ?: '-'); ?></div></div>
                            <div class="info"><label>Platform Lead ID</label><div><?= e($selectedLead['platform_lead_id'] ?: '-'); ?></div></div>
                        </div>

                        <div class="contact-row">
                            <a class="contact quotation" href="#crm-package" data-crm-open="package"><i class="fa-solid fa-file-invoice-dollar"></i> Quotation</a>
                            <?php if (!empty($selectedLead['mobile'])): ?>
                                <a class="contact" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $selectedLead['mobile'])); ?>"><i class="fa-solid fa-phone"></i> Call</a>
                            <?php endif; ?>
                            <?php if (crmWhatsAppNumber($selectedLead['mobile']) !== ''): ?>
                                <a class="contact" target="_blank" href="https://wa.me/<?= e(crmWhatsAppNumber($selectedLead['mobile'])); ?>"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
                            <?php endif; ?>
                            <?php if (!empty($selectedLead['email'])): ?>
                                <a class="contact" href="mailto:<?= e($selectedLead['email']); ?>"><i class="fa-regular fa-envelope"></i> Email</a>
                            <?php endif; ?>
                            <a class="contact quotation" href="admin-supplier-requests.php?lead=<?= (int)$selectedLead['id']; ?><?= !empty($selectedTrip['id']) ? '&trip='.(int)$selectedTrip['id'] : ''; ?>">
                                <i class="fa-solid fa-handshake"></i> Supplier Sourcing
                            </a>
                            <a class="contact quotation" href="admin-travel-suggestions.php?lead=<?= (int)$selectedLead['id']; ?><?= !empty($selectedTrip['id']) ? '&trip='.(int)$selectedTrip['id'] : ''; ?><?= !empty($selectedTrip['package_id']) ? '&package='.(int)$selectedTrip['package_id'] : ''; ?>">
                                <i class="fa-solid fa-plane-departure"></i> Flight / Train Suggestions
                            </a>
                            <?php if ($selectedTrip): ?>
                                <a class="contact quotation" href="admin-trip-contract-rates.php?trip_id=<?= (int)$selectedTrip['id']; ?>">
                                    <i class="fa-solid fa-table-cells"></i> Contract Rate Engine
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($selectedLead['social_profile_url'])): ?>
                                <a class="contact" target="_blank" rel="noopener" href="<?= e($selectedLead['social_profile_url']); ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i> Social Profile</a>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($selectedLead['message'])): ?>
                            <div class="info" style="margin-top:13px"><label>Customer Requirement</label><div><?= nl2br(e($selectedLead['message'])); ?></div></div>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="card">
                    <div class="card-head"><h2>Lead Management</h2></div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                            <input type="hidden" name="action" value="save_lead">
                            <input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>">

                            <div class="two-fields">
                                <div class="field">
                                    <label>Status</label>
                                    <select class="control" name="status" required>
                                        <?php foreach ($statuses as $item): ?>
                                            <option value="<?= e($item); ?>" <?= $selectedLead['status'] === $item ? 'selected' : ''; ?>><?= e(crmStatusLabel($item)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Priority</label>
                                    <select class="control" name="priority" required>
                                        <?php foreach ($priorities as $item): ?>
                                            <option value="<?= e($item); ?>" <?= $selectedLead['priority'] === $item ? 'selected' : ''; ?>><?= e(crmPriorityLabel($item)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="two-fields">
                                <div class="field"><label>Lead Source</label><select class="control" name="lead_source"><?php foreach ($sources as $item): ?><option value="<?= e($item); ?>" <?= $selectedLead['lead_source'] === $item ? 'selected' : ''; ?>><?= e(crmSourceLabel($item)); ?></option><?php endforeach; ?></select></div>
                                <div class="field"><label>Lead Score (0–100)</label><input class="control" type="number" min="0" max="100" name="lead_score" value="<?= (int)$selectedLead['lead_score']; ?>"></div>
                            </div>

                            <div class="field"><label>Campaign Name</label><input class="control" name="campaign_name" value="<?= e((string)$selectedLead['campaign_name']); ?>"></div>
                            <div class="two-fields">
                                <div class="field"><label>Social Profile Name</label><input class="control" name="social_profile_name" value="<?= e((string)$selectedLead['social_profile_name']); ?>"></div>
                                <div class="field"><label>Platform Lead ID</label><input class="control" name="platform_lead_id" value="<?= e((string)$selectedLead['platform_lead_id']); ?>"></div>
                            </div>
                            <div class="field"><label>Social Profile URL</label><input class="control" type="url" name="social_profile_url" value="<?= e((string)$selectedLead['social_profile_url']); ?>"></div>
                            <div class="two-fields">
                                <div class="field"><label>Estimated Sale Value</label><input class="control" type="number" min="0" step="0.01" name="estimated_value" value="<?= e((string)$selectedLead['estimated_value']); ?>"></div>
                                <div class="field"><label>Next Follow Up</label><input class="control" type="datetime-local" name="next_follow_up_at" value="<?= e(crmDateTimeInput($selectedLead['next_follow_up_at'])); ?>"></div>
                            </div>
                            <div class="field"><label>Tags</label><input class="control" name="tags" value="<?= e((string)$selectedLead['tags']); ?>" placeholder="honeymoon, family, urgent"></div>
                            <div class="field"><label>Lost / Closed Reason</label><input class="control" name="lost_reason" value="<?= e((string)$selectedLead['lost_reason']); ?>"></div>
                            <div class="field"><label>Admin Notes</label><textarea class="control" name="admin_notes"><?= e((string)$selectedLead['admin_notes']); ?></textarea></div>
                            <button class="button save" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Lead</button>
                        </form>
                    </div>
                </aside>
            </section>


            <section class="card crm-workspace-panel ts-costing-workspace" data-crm-panel="costing" id="crm-costing">
                <div class="card-head"><div><h2><i class="fa-solid fa-layer-group"></i> Trip Services & Cost Sheet</h2><p style="margin:4px 0 0;color:#64748b;font-size:11px;">Internal costing only — never shown to customer.</p></div></div>
                <div class="card-body">
                <?php if(!$selectedTrip): ?>
                    <div class="ts-cost-empty"><strong>Create the Trip first</strong><span>Services and Cost Sheet belong to the permanent Trip ID.</span></div>
                <?php elseif(!crmServiceRequirementsReady($pdo)||!crmCostSheetReady($pdo)): ?>
                    <div class="ts-cost-empty"><strong>Install Phase 0B SQL</strong><span>Import TRAVSCOPE_phase0B_service_requirements_cost_sheet.sql once.</span></div>
                <?php else: ?>
                    <div class="ts-cost-layout">
                        <div class="ts-cost-column">
                            <div class="ts-cost-title"><h3>Services Required</h3><span><?= count($tripServiceRequirements); ?> service<?= count($tripServiceRequirements)===1?'':'s'; ?></span></div>
                            <?php if($tripServiceRequirements): ?><div class="ts-service-list">
                            <?php foreach($tripServiceRequirements as $service): ?><div class="ts-service-card"><div><strong><?= e((string)$service['title']); ?></strong><small><?= e(crmServiceRequirementTypeLabel((string)$service['service_type'])); ?> · <?= e(crmServiceRequirementStatusLabel((string)$service['status'])); ?><?= !empty($service['destination_name'])?' · '.e((string)$service['destination_name']):''; ?></small><?php if(!empty($service['specifications'])):?><p><?= nl2br(e((string)$service['specifications'])); ?></p><?php endif;?><div style="margin-top:7px"><a class="button orange" style="min-height:28px;padding:6px 9px;font-size:9px" href="admin-supplier-requests.php?lead=<?=(int)$selectedLead['id'];?>&trip=<?=(int)$selectedTrip['id'];?>&service=<?=(int)$service['id'];?>"><i class="fa-solid fa-paper-plane"></i> Source Supplier for This Service</a></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>"><input type="hidden" name="action" value="delete_service_requirement"><input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>"><input type="hidden" name="trip_id" value="<?= (int)$selectedTrip['id']; ?>"><input type="hidden" name="service_requirement_id" value="<?= (int)$service['id']; ?>"><button class="ts-trash" type="submit"><i class="fa-regular fa-trash-can"></i></button></form></div><?php endforeach; ?>
                            </div><?php else:?><div class="ts-cost-empty small"><strong>No structured services yet</strong><span>Add hotel, transport, activity, flight or other service.</span></div><?php endif;?>
                            <details class="ts-add-box"><summary><i class="fa-solid fa-plus"></i> Add Service Requirement</summary><form method="post" class="ts-form"><input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>"><input type="hidden" name="action" value="save_service_requirement"><input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>"><input type="hidden" name="trip_id" value="<?= (int)$selectedTrip['id']; ?>">
                            <div class="field"><label>Service</label><select class="control" name="service_type"><?php foreach(crmServiceRequirementTypes() as $st):?><option value="<?=e($st);?>"><?=e(crmServiceRequirementTypeLabel($st));?></option><?php endforeach;?></select></div>
                            <div class="field"><label>Title</label><input class="control" name="service_title" placeholder="4 Star Hotel / Private Vehicle..."></div>
                            <div class="field"><label>Destination</label><select class="control" name="service_destination_id"><option value="">Use Trip / Not specific</option><?php foreach($crmDestinations as $d):?><option value="<?=(int)$d['id'];?>"><?=e((string)$d['name']);?></option><?php endforeach;?></select></div>
                            <div class="field"><label>Status</label><select class="control" name="service_status"><?php foreach(crmServiceRequirementStatuses() as $ss):?><option value="<?=e($ss);?>"><?=e(crmServiceRequirementStatusLabel($ss));?></option><?php endforeach;?></select></div>
                            <div class="field"><label>From</label><input class="control" type="date" name="service_date_from" value="<?=e((string)($selectedLead['travel_date']??''));?>"></div><div class="field"><label>To</label><input class="control" type="date" name="service_date_to"></div>
                            <div class="field"><label>Quantity</label><input class="control" type="number" min="0.01" step="0.01" name="service_quantity" value="1"></div><div class="field full"><label>Specification</label><textarea class="control" name="service_specifications" placeholder="Rooms, meal, vehicle, pickup/drop, special requirements..."></textarea></div><div class="field full"><button class="button blue" type="submit">Add to Trip</button></div></form></details>
                        </div>
                        <div class="ts-cost-column">
                            <div class="ts-cost-title"><h3>Trip Cost Sheet</h3><i class="fa-solid fa-lock"></i></div>
                            <div class="ts-cost-summary"><div><span>Supplier Cost</span><strong><?= $activeCostSheet?e(crmMoney((float)$activeCostSheet['supplier_cost_total'],(string)$activeCostSheet['currency'].' ')):'-';?></strong></div><div><span>Other Direct</span><strong><?= $activeCostSheet?e(crmMoney((float)$activeCostSheet['direct_cost_total'],(string)$activeCostSheet['currency'].' ')):'-';?></strong></div><div class="total"><span>Travscope Cost</span><strong><?= $activeCostSheet?e(crmMoney((float)$activeCostSheet['travscope_cost_total'],(string)$activeCostSheet['currency'].' ')):'-';?></strong></div></div>
                            <form method="post" class="ts-actions"><input type="hidden" name="csrf_token" value="<?=e($csrfToken);?>"><input type="hidden" name="action" value="sync_cost_sheet"><input type="hidden" name="lead_id" value="<?=(int)$selectedLead['id'];?>"><input type="hidden" name="trip_id" value="<?=(int)$selectedTrip['id'];?>"><button class="button green" type="submit"><i class="fa-solid fa-rotate"></i> Refresh Selected Supplier Costs</button><a class="button blue" href="admin-supplier-requests.php?lead=<?=(int)$selectedLead['id'];?><?=!empty($selectedTrip['id'])?'&trip='.(int)$selectedTrip['id']:''?>">Supplier Sourcing</a><a class="button orange" href="admin-trip-contract-rates.php?trip_id=<?=(int)$selectedTrip['id'];?>"><i class="fa-solid fa-table-cells"></i> Contract Rate Engine</a><a class="button blue" href="admin-trip-hotel-allocations.php?trip_id=<?=(int)$selectedTrip['id'];?>"><i class="fa-solid fa-hotel"></i> Destination Hotels</a><a class="button blue" href="admin-trip-transport-allocations.php?trip_id=<?=(int)$selectedTrip['id'];?>"><i class="fa-solid fa-van-shuttle"></i> Transport</a><a class="button blue" href="admin-trip-commercial-hub.php?trip_id=<?=(int)$selectedTrip['id'];?>"><i class="fa-solid fa-comments-dollar"></i> Commercial Hub</a></form>
                            <?php if($activeCostSheetItems):?><div class="ts-cost-items"><?php foreach($activeCostSheetItems as $ci):?><div class="ts-cost-item"><div><strong><?=e((string)$ci['description']);?></strong><small><?php if($ci['source_type']==='supplier_quote'):?>Supplier Quote<?php elseif($ci['source_type']==='contract_rate'):?>Contract Rate<?php else:?>Direct Cost<?php endif;?><?=!empty($ci['supplier_name'])?' · '.e((string)$ci['supplier_name']):'';?></small></div><b><?=e(crmMoney((float)$ci['line_cost'],(string)$activeCostSheet['currency'].' '));?></b></div><?php endforeach;?></div><?php else:?><div class="ts-cost-empty small"><strong>No cost items yet</strong><span>Use a valid Contract Rate or select supplier quote(s), then refresh this Cost Sheet.</span></div><?php endif;?>
                            <details class="ts-add-box"><summary><i class="fa-solid fa-plus"></i> Add Other Direct Cost</summary><form method="post" class="ts-form"><input type="hidden" name="csrf_token" value="<?=e($csrfToken);?>"><input type="hidden" name="action" value="add_manual_cost_item"><input type="hidden" name="lead_id" value="<?=(int)$selectedLead['id'];?>"><input type="hidden" name="trip_id" value="<?=(int)$selectedTrip['id'];?>"><div class="field"><label>Type</label><select class="control" name="manual_cost_type"><?php foreach(crmServiceRequirementTypes() as $st):?><option value="<?=e($st);?>"><?=e(crmServiceRequirementTypeLabel($st));?></option><?php endforeach;?></select></div><div class="field"><label>Amount</label><input class="control" type="number" min="0.01" step="0.01" name="manual_cost_amount" required></div><div class="field full"><label>Description</label><input class="control" name="manual_cost_description" required placeholder="Permit / parking / local handling..."></div><div class="field full"><button class="button orange" type="submit">Add Direct Cost</button></div></form></details>
                            <div class="ts-flow">Supplier Cost <i class="fa-solid fa-arrow-right"></i> Cost Sheet <i class="fa-solid fa-arrow-right"></i> Existing Markup <i class="fa-solid fa-arrow-right"></i> <strong>Customer Price</strong></div>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
            </section>

            <section class="card package-studio-card crm-workspace-panel" data-crm-panel="package" id="packageStudio">
                <div class="card-head studio-head">
                    <div>
                        <span class="studio-eyebrow">ENQUIRY → COST → QUOTATION</span>
                        <h2>Quotation Builder</h2>
                        <p>One clear workspace: confirm customer requirement, choose package and supplier cost, review itinerary/services, set selling price, then save and send the quotation.</p>
                    </div>
                    <span class="safe-badge"><i class="fa-solid fa-shield-halved"></i> Master Package Protected</span>
                </div>

                <div class="card-body">
                    <?php
                    $qDeskSupplierNames = implode(', ', array_map('strval', (array)$crmQuotationCostSupplierNames));
                    $qDeskSupplierCost = (float)$crmQuotationCostTotal;
                    $qDeskSupplierCurrency = (string)$crmQuotationCostCurrency;
                    $qDeskHotelServices = 0;
                    $qDeskTransportServices = 0;
                    $qDeskOtherServices = 0;
                    foreach ((array)$tripServiceRequirements as $qDeskService) {
                        $qDeskType = strtolower(trim((string)($qDeskService['service_type'] ?? 'other')));
                        if ($qDeskType === 'hotel') $qDeskHotelServices++;
                        elseif ($qDeskType === 'transport') $qDeskTransportServices++;
                        else $qDeskOtherServices++;
                    }
                    $qDeskLatestVersion = $packageVersions[0] ?? null;
                    ?>

                    <section class="qdesk-shell" id="qdeskShell" data-supplier-cost="<?= e((string)$qDeskSupplierCost); ?>" data-currency="<?= e($qDeskSupplierCurrency); ?>">
                        <div class="qdesk-flow">
                            <button type="button" class="qdesk-step is-done" data-crm-open="overview"><b>1</b><span>Customer Requirement<small>Enquiry details</small></span></button>
                            <button type="button" class="qdesk-step <?= $qDeskSupplierCost > 0 ? 'is-done' : ''; ?>" data-crm-open="costing"><b>2</b><span>Supplier & Cost<small>Package / DMC cost</small></span></button>
                            <button type="button" class="qdesk-step" data-qdesk-scroll="crmPackageSelect"><b>3</b><span>Package & Itinerary<small>Customer content</small></span></button>
                            <button type="button" class="qdesk-step" data-qdesk-scroll="crmCommissionValue"><b>4</b><span>Selling Price<small>Markup & discount</small></span></button>
                            <button type="button" class="qdesk-step <?= $qDeskLatestVersion ? 'is-done' : ''; ?>" data-qdesk-scroll="qdeskSaveBar"><b>5</b><span>Save & Send<small>Version / PDF / WhatsApp</small></span></button>
                        </div>

                        <div class="qdesk-grid">
                            <article class="qdesk-card qdesk-customer">
                                <div class="qdesk-card-head"><span><i class="fa-solid fa-user"></i> Customer Requirement</span><button type="button" data-crm-open="overview">Review</button></div>
                                <strong><?= e((string)$selectedLead['name']); ?></strong>
                                <div class="qdesk-facts">
                                    <span><small>Destination</small><b><?= e((string)($selectedLead['destination'] ?: 'Pending')); ?></b></span>
                                    <span><small>Travel</small><b><?= e(crmDate($selectedLead['travel_date'])); ?></b></span>
                                    <span><small>Guests</small><b><?= (int)$selectedLead['adults']; ?> Adult<?= (int)$selectedLead['adults']===1?'':'s'; ?><?= (int)$selectedLead['children']>0 ? ' + '.(int)$selectedLead['children'].' Child' : ''; ?></b></span>
                                </div>
                            </article>

                            <article class="qdesk-card qdesk-package">
                                <div class="qdesk-card-head"><span><i class="fa-solid fa-suitcase-rolling"></i> Package</span><button type="button" data-qdesk-scroll="crmPackageSelect">Choose / Edit</button></div>
                                <strong id="qdeskPackageTitle"><?= $qDeskLatestVersion ? e((string)$qDeskLatestVersion['package_title']) : 'Select a package'; ?></strong>
                                <div class="qdesk-facts">
                                    <span><small>Destination</small><b id="qdeskDestination">-</b></span>
                                    <span><small>Duration</small><b id="qdeskDuration">-</b></span>
                                    <span><small>Itinerary</small><b id="qdeskDays">-</b></span>
                                </div>
                            </article>

                            <article class="qdesk-card qdesk-supplier">
                                <div class="qdesk-card-head"><span><i class="fa-solid fa-handshake"></i> Supplier Cost</span><?php if($selectedTrip): ?><a href="admin-trip-contract-rates.php?trip_id=<?= (int)$selectedTrip['id']; ?>">Choose Rate</a><?php endif; ?></div>
                                <strong><?= $qDeskSupplierNames !== '' ? e($qDeskSupplierNames) : 'Supplier package/rate not selected'; ?></strong>
                                <div class="qdesk-money"><small>Supplier Payable</small><b><?= e($qDeskSupplierCurrency); ?> <?= number_format($qDeskSupplierCost,2); ?></b></div>
                                <div class="qdesk-service-pills">
                                    <span><i class="fa-solid fa-hotel"></i> <?= $qDeskHotelServices; ?> hotel service<?= $qDeskHotelServices===1?'':'s'; ?></span>
                                    <span><i class="fa-solid fa-van-shuttle"></i> <?= $qDeskTransportServices; ?> transport</span>
                                    <span><i class="fa-solid fa-puzzle-piece"></i> <?= $qDeskOtherServices; ?> other</span>
                                </div>
                            </article>

                            <article class="qdesk-card qdesk-selling">
                                <div class="qdesk-card-head"><span><i class="fa-solid fa-indian-rupee-sign"></i> Customer Selling Price</span><button type="button" data-qdesk-scroll="crmCommissionValue">Pricing</button></div>
                                <strong id="qdeskFinalPrice"><?= $qDeskLatestVersion ? e(crmMoney((float)$qDeskLatestVersion['final_price'], '₹')) : 'Not calculated'; ?></strong>
                                <div class="qdesk-facts">
                                    <span><small>Package Ref.</small><b id="qdeskPackageCost">-</b></span>
                                    <span><small>Markup</small><b id="qdeskMarkup">-</b></span>
                                    <span><small>Margin</small><b id="qdeskMargin">-</b></span>
                                </div>
                            </article>
                        </div>

                        <div class="qdesk-actions">
                            <span><i class="fa-solid fa-circle-info"></i> Recommended order: Enquiry → Supplier Package/Rate → Hotel & Vehicle → Markup → Save Version → PDF/WhatsApp.</span>
                            <div>
                                <?php if($selectedTrip): ?>
                                    <a class="button orange" id="qdeskSupplierPackageRate" data-trip-id="<?= (int)$selectedTrip['id']; ?>" href="admin-trip-contract-rates.php?trip_id=<?= (int)$selectedTrip['id']; ?><?= !empty($selectedTrip['package_id']) ? '&package_id='.(int)$selectedTrip['package_id'] : ''; ?>"><i class="fa-solid fa-table-cells"></i> Supplier Package Rate</a>
                                    <a class="button light" href="admin-supplier-requests.php?lead=<?= (int)$selectedLead['id']; ?>&trip=<?= (int)$selectedTrip['id']; ?>"><i class="fa-solid fa-handshake"></i> Supplier / RFQ</a>
                                <?php endif; ?>
                                <?php if($qDeskLatestVersion): ?>
                                    <a class="button green" target="_blank" rel="noopener" href="<?= e(BASE_URL . 'admin-crm.php?lead='.(int)$selectedLead['id'].'&quotation_preview=1&version='.(int)$qDeskLatestVersion['id']); ?>"><i class="fa-solid fa-file-pdf"></i> Preview Latest</a>
                                <?php endif; ?>
                                <button type="button" class="button blue" data-qdesk-scroll="qdeskSaveBar"><i class="fa-solid fa-file-circle-plus"></i> Finish Quotation</button>
                            </div>
                        </div>
                    </section>

                    <div class="studio-layout">
                        <form method="post" enctype="multipart/form-data" id="customerPackageForm" class="studio-editor">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                            <input type="hidden" name="action" value="save_package_version">
                            <input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>">
                            <input type="hidden" name="trip_id" value="<?= (int)($selectedTrip['id'] ?? 0); ?>">

                            <div class="studio-toolbar">
                                <div class="field toolbar-package">
                                    <label><i class="fa-solid fa-copy" style="color:#2563eb;margin-right:6px"></i> Load Details From Master Package <span style="color:#64748b;font-weight:700">(Optional)</span></label>
                                    <select class="control" name="package_id" id="crmPackageSelect">
                                        <option value="">Start a Custom Package</option>
                                        <?php foreach ($availablePackages as $pkg): ?>
                                            <option
                                                value="<?= (int)$pkg['id']; ?>"
                                                data-package="<?= e(json_encode($pkg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>"
                                                <?= (int)($selectedLead['package_id'] ?? 0) === (int)$pkg['id'] ? 'selected' : ''; ?>
                                            >
                                                <?= e($pkg['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="muted" style="display:block;margin-top:7px;line-height:1.45">Choose a master package once to copy its details into this quotation. You may edit the copied quotation safely; the original Package Master remains unchanged.</small>
                                </div>
                                <button type="button" class="button light" id="clearCustomerPackage">
                                    <i class="fa-solid fa-eraser"></i> Clear Editor
                                </button>
                            </div>

                            <details class="studio-section quote-section-blue" open>
                                <summary><i class="fa-solid fa-circle-info"></i> Step 3A · Package Details</summary>
                                <div class="studio-section-body form-grid">
                                    <div class="field full">
                                        <label>Package Title *</label>
                                        <input class="control" name="package_title" id="crmPackageTitle" required>
                                    </div>
                                    <div class="field">
                                        <label>Destination</label>
                                        <select class="control" name="destination_id" id="crmDestinationId">
                                            <option value="">Select Destination</option>
                                            <?php foreach ($crmDestinations as $destination): ?>
                                                <option value="<?= (int)$destination['id']; ?>">
                                                    <?= e($destination['name']); ?><?= !empty($destination['country']) ? ' — ' . e($destination['country']) : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label>Package Type</label>
                                        <select class="control" name="package_type" id="crmPackageType">
                                            <?php if ($crmPackageTypes): ?>
                                                <?php foreach ($crmPackageTypes as $type): ?>
                                                    <?php $typeValue = $type['slug'] ?? $type['code'] ?? $type['type'] ?? ''; ?>
                                                    <option value="<?= e((string)$typeValue); ?>"><?= e((string)($type['name'] ?? ucwords(str_replace('_', ' ', (string)$typeValue)))); ?></option>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <option value="holiday">Holiday</option>
                                                <option value="honeymoon">Honeymoon</option>
                                                <option value="family">Family</option>
                                                <option value="adventure">Adventure</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="field">
                                        <label>Package Code</label>
                                        <input class="control" name="package_code" id="crmPackageCode">
                                    </div>
                                    <div class="field">
                                        <label>Currency</label>
                                        <input class="control" name="currency" id="crmCurrency" value="INR" maxlength="10">
                                    </div>
                                    <div class="field full">
                                        <label>Short Description</label>
                                        <textarea class="control" name="short_description" id="crmShortDescription"></textarea>
                                    </div>
                                    <div class="field full">
                                        <label>Full Package Description</label>
                                        <textarea class="control studio-large" name="package_description" id="crmPackageDescription"></textarea>
                                    </div>
                                    <div class="field">
                                        <label>Duration Days</label>
                                        <input class="control" type="number" min="0" name="duration_days" id="crmDurationDays" value="0">
                                    </div>
                                    <div class="field">
                                        <label>Duration Nights</label>
                                        <input class="control" type="number" min="0" name="duration_nights" id="crmDurationNights" value="0">
                                    </div>
                                </div>
                            </details>

                            <details class="studio-section quote-section-violet">
                                <summary><i class="fa-regular fa-images"></i> Optional · Package Images</summary>
                                <div class="studio-section-body">
                                    <div class="section-intro">
                                        <strong>Choose images exactly like Admin Packages:</strong>
                                        Computer / PC, Web Gallery, or combined Pexels + Unsplash photo search.
                                    </div>

                                    <div class="image-editor-grid">

                                        <div class="field">
                                            <label>Main Image</label>

                                            <input
                                                type="hidden"
                                                name="existing_main_image"
                                                id="crmExistingMainImage"
                                            >

                                            <input
                                                class="control file-control"
                                                type="file"
                                                name="main_image"
                                                id="crmMainImage"
                                                accept="image/jpeg,image/png,image/webp"
                                                hidden
                                            >

                                            <div class="crm-image-choice-row">
                                                <button
                                                    type="button"
                                                    class="crm-image-choice crm-choice-pc"
                                                    data-media-pc="main"
                                                >
                                                    <i class="fa-solid fa-computer"></i>
                                                    Computer / PC
                                                </button>

                                                <button
                                                    type="button"
                                                    class="crm-image-choice crm-choice-web"
                                                    data-media-web="main"
                                                >
                                                    <i class="fa-regular fa-images"></i>
                                                    Web Gallery
                                                </button>

                                                <button
                                                    type="button"
                                                    class="crm-image-choice crm-choice-free"
                                                    data-media-free="main"
                                                >
                                                    <i class="fa-solid fa-magnifying-glass"></i>
                                                    Search Pexels + Unsplash
                                                </button>
                                            </div>

                                            <div
                                                class="studio-image-preview"
                                                id="crmMainPreview"
                                            >
                                                <span>No main image selected</span>
                                            </div>
                                        </div>


                                        <div class="field">
                                            <label>Additional Gallery Images</label>

                                            <input
                                                class="control file-control"
                                                type="file"
                                                name="gallery_images[]"
                                                id="crmGalleryImages"
                                                accept="image/jpeg,image/png,image/webp"
                                                multiple
                                                hidden
                                            >

                                            <div class="crm-image-choice-row">
                                                <button
                                                    type="button"
                                                    class="crm-image-choice crm-choice-pc"
                                                    data-media-pc="gallery"
                                                >
                                                    <i class="fa-solid fa-computer"></i>
                                                    Computer / PC
                                                </button>

                                                <button
                                                    type="button"
                                                    class="crm-image-choice crm-choice-web"
                                                    data-media-web="gallery"
                                                >
                                                    <i class="fa-regular fa-images"></i>
                                                    Web Gallery
                                                </button>

                                                <button
                                                    type="button"
                                                    class="crm-image-choice crm-choice-free"
                                                    data-media-free="gallery"
                                                >
                                                    <i class="fa-solid fa-magnifying-glass"></i>
                                                    Search Pexels + Unsplash
                                                </button>
                                            </div>

                                            <div
                                                class="gallery-preview-grid"
                                                id="crmGalleryPreview"
                                            ></div>

                                            <div id="crmExistingGalleryInputs"></div>

                                            <div class="crm-gallery-help">
                                                You can select multiple Web Gallery or Free Photos one after another.
                                                Close the image window when finished.
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            </details>

                            <details class="studio-section quote-section-green">
                                <summary><i class="fa-solid fa-calendar-days"></i> Reference · Package Master Selling Rates</summary>
                                <div class="studio-section-body">
                                    <div class="section-intro">
                                        <strong>No manual quotation price entry.</strong>
                                        Date-wise 3 / 4 / 5 Star rates and child age rates are read directly from the selected Package Master.
                                    </div>

                                    <div class="pricing-table-wrap">
                                        <table class="pricing-table">
                                            <thead>
                                                <tr>
                                                    <th>Valid From</th>
                                                    <th>Valid To</th>
                                                    <th>3 Star</th>
                                                    <th>4 Star</th>
                                                    <th>5 Star</th>
                                                </tr>
                                            </thead>
                                            <tbody id="crmMasterPriceRows">
                                                <tr><td colspan="5" class="muted">Select a master package to load its date-wise prices.</td></tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="crm-child-master-box">
                                        <strong>Child Age Pricing</strong>
                                        <div id="crmMasterChildPrices" class="crm-child-rate-list">
                                            <span class="muted">Child rates will load from Package Master.</span>
                                        </div>
                                    </div>
                                </div>
                            </details>

                            <details class="studio-section quote-section-cyan crm-itinerary-section" open>
                                <summary><i class="fa-solid fa-route"></i> Step 3B · Professional Itinerary Studio <span class="crm-studio-summary-pill">V24.97</span></summary>
                                <div class="studio-section-body crm-itinerary-studio" id="crmItineraryStudio"
                                     data-lead="<?= (int)$selectedLead['id']; ?>"
                                     data-trip="<?= (int)($selectedTrip['id'] ?? 0); ?>">
                                    <div class="crm-studio-heading">
                                        <div>
                                            <div class="crm-studio-kicker">TRAVSCOPE · CUSTOMER JOURNEY EDITOR</div>
                                            <h3>Itinerary Studio</h3>
                                            <p>Work on one day at a time. All days are retained in the same quotation.</p>
                                        </div>
                                        <div class="crm-studio-heading-actions">
                                            <span class="crm-studio-draft-status" id="crmStudioDraftStatus" aria-live="polite">Browser draft not saved yet</span>
                                            <button type="button" class="crm-studio-small-btn" id="crmStudioRestoreDraft" hidden><i class="fa-solid fa-clock-rotate-left"></i> Restore draft</button>
                                            <button type="button" class="crm-studio-small-btn" id="crmStudioFocus"><i class="fa-solid fa-expand"></i> Focus mode</button>
                                        </div>
                                    </div>
                                    <div class="crm-studio-columns">
                                        <nav class="crm-studio-day-rail" aria-label="Itinerary day navigation">
                                            <div class="crm-studio-rail-head"><strong>TRAVEL DAYS</strong><span id="crmStudioDayCount">0 days</span></div>
                                            <div id="crmStudioDayNav" class="crm-studio-day-nav"></div>
                                            <button type="button" class="crm-studio-add-day" id="crmStudioAddDay"><i class="fa-solid fa-plus"></i> Add a day</button>
                                            <div class="crm-studio-progress" id="crmStudioDayProgress">Select a package or add a day.</div>
                                        </nav>
                                        <section class="crm-studio-main" aria-label="Selected day editor">
                                            <div class="crm-studio-active-toolbar">
                                                <strong id="crmStudioActiveTitle">Select a day to begin</strong>
                                                <div>
                                                    <button type="button" id="crmStudioPrevious" aria-label="Previous day"><i class="fa-solid fa-chevron-left"></i> Prev</button>
                                                    <button type="button" id="crmStudioNext" aria-label="Next day">Next <i class="fa-solid fa-chevron-right"></i></button>
                                                    <button type="button" id="crmStudioUndo" title="Undo a title, narrative or route edit on this day"><i class="fa-solid fa-rotate-left"></i> Undo</button>
                                                    <button type="button" id="crmStudioRedo" title="Redo an undone edit"><i class="fa-solid fa-rotate-right"></i> Redo</button>
                                                    <button type="button" id="crmStudioDuplicate" title="Duplicate this day after the selected day"><i class="fa-regular fa-copy"></i> Duplicate</button>
                                                    <button type="button" id="crmStudioRemove" class="crm-studio-danger" title="Remove selected itinerary day"><i class="fa-solid fa-trash-can"></i></button>
                                                </div>
                                            </div>
                                            <div class="crm-studio-empty" id="crmStudioEmpty">Select a package with an itinerary or choose <strong>Add a day</strong> to start writing.</div>
                                            <div class="itinerary-editor" id="crmItineraryRows"></div>
                                            <div class="crm-studio-editor-foot"><span id="crmStudioWordCounter">Narrative: 0 words</span><span id="crmStudioWordHint">Editorial target: 190–200 words</span></div>
                                        </section>
                                        <aside class="crm-studio-live" aria-label="Customer presentation preview">
                                            <div class="crm-studio-live-head"><div><span>LIVE PREVIEW</span><strong>Customer presentation</strong></div><i class="fa-regular fa-eye"></i></div>
                                            <div id="crmStudioLiveImage" class="crm-studio-live-image"><i class="fa-regular fa-image"></i><span>No image selected</span></div>
                                            <div class="crm-studio-live-content"><div id="crmStudioLiveDay" class="crm-studio-live-day">DAY 01 · YOUR JOURNEY</div><h4 id="crmStudioLiveTitle">Day title appears here</h4><p id="crmStudioLiveDescription">The customer-facing day narrative will appear here as you type.</p><div class="crm-studio-route-preview" id="crmStudioLiveRoute"></div></div>
                                            <div class="crm-studio-live-note"><i class="fa-solid fa-circle-info"></i> Layout preview; final PDF may paginate differently. Save a quotation version to review its complete PDF.</div>
                                        </aside>
                                    </div>
                                    <div class="crm-studio-bottom-note"><i class="fa-solid fa-shield-halved"></i> Browser draft auto-save protects your text in this tab only; it does <strong>not</strong> create a customer quotation version. Uploaded PC images are not stored in browser drafts. Use <strong>Save Customer Quotation</strong> below to create a permanent version.</div>
                                </div>
                            </details>

                            <details class="studio-section quote-section-violet" open>
                                <summary><i class="fa-solid fa-plane-departure"></i> Step 3C · Flight & Train Suggestions</summary>
                                <div class="studio-section-body">
                                    <?php
                                    $crmTravelSuggestions = function_exists('tsTravelSuggestions') && $selectedLead
                                        ? tsTravelSuggestions($pdo,(int)$selectedLead['id'],(int)($selectedTrip['id'] ?? 0))
                                        : ['flights'=>[],'trains'=>[]];
                                    $crmSuggestedFlights=(array)($crmTravelSuggestions['flights']??[]);
                                    $crmSuggestedTrains=(array)($crmTravelSuggestions['trains']??[]);
                                    ?>
                                    <div class="section-intro">
                                        <strong>Suggestion only — not ticketing.</strong> Search or enter suitable flight/train options, select what should be shown to the client, and save the quotation. API fares can be hidden or included inside the package price.
                                    </div>
                                    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px">
                                        <div style="padding:13px;border:1px solid #bfdbfe;border-radius:12px;background:#f5f9ff">
                                            <div style="display:flex;justify-content:space-between;gap:10px"><strong style="color:#1d4ed8"><i class="fa-solid fa-plane"></i> Suggested Flights</strong><span class="badge" style="background:#dbeafe;color:#1d4ed8"><?= count($crmSuggestedFlights); ?> selected</span></div>
                                            <?php if($crmSuggestedFlights): ?><div style="margin-top:8px;display:grid;gap:6px"><?php foreach(array_slice($crmSuggestedFlights,0,3) as $f): ?><div style="padding:7px 8px;background:#fff;border-radius:8px;font-size:10px"><b><?= e((string)$f['origin_code']); ?> → <?= e((string)$f['destination_code']); ?></b> · <?= e(trim((string)($f['airline_name']??'').' '.(string)($f['flight_number']??''))); ?> · <?= (int)($f['stops']??0)===0?'Direct':(int)$f['stops'].' stop'; ?></div><?php endforeach; ?></div><?php else: ?><div class="muted" style="margin-top:8px;font-size:10px">No flight suggestion selected yet.</div><?php endif; ?>
                                        </div>
                                        <div style="padding:13px;border:1px solid #bbf7d0;border-radius:12px;background:#f3fdf8">
                                            <div style="display:flex;justify-content:space-between;gap:10px"><strong style="color:#047857"><i class="fa-solid fa-train"></i> Suggested Trains</strong><span class="badge" style="background:#dcfce7;color:#047857"><?= count($crmSuggestedTrains); ?> selected</span></div>
                                            <?php if($crmSuggestedTrains): ?><div style="margin-top:8px;display:grid;gap:6px"><?php foreach(array_slice($crmSuggestedTrains,0,3) as $r): ?><div style="padding:7px 8px;background:#fff;border-radius:8px;font-size:10px"><b><?= e((string)$r['origin_code']); ?> → <?= e((string)$r['destination_code']); ?></b> · <?= e(trim((string)($r['train_name']??'').' '.(string)($r['train_number']??''))); ?></div><?php endforeach; ?></div><?php else: ?><div class="muted" style="margin-top:8px;font-size:10px">No train suggestion selected yet.</div><?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if($selectedLead): ?><div style="margin-top:11px"><a class="button blue" href="admin-travel-suggestions.php?lead=<?= (int)$selectedLead['id']; ?><?= $selectedTrip?'&trip='.(int)$selectedTrip['id']:''; ?><?= !empty($selectedTrip['package_id'])?'&package='.(int)$selectedTrip['package_id']:''; ?>"><i class="fa-solid fa-magnifying-glass"></i> Manage Flight & Train Suggestions</a></div><?php endif; ?>
                                </div>
                            </details>

                            <details class="studio-section quote-section-orange">
                                <summary><i class="fa-solid fa-list-check"></i> Step 3D · Inclusions, Exclusions & Terms</summary>
                                <div class="studio-section-body form-grid">
                                    <div class="field full">
                                        <label>Tour Highlights</label>
                                        <textarea class="control studio-large" name="tour_highlights" id="crmTourHighlights"></textarea>
                                    </div>
                                    <div class="field">
                                        <label>Inclusions</label>
                                        <textarea class="control studio-large" name="inclusions" id="crmPackageInclusions"></textarea>
                                    </div>
                                    <div class="field">
                                        <label>Exclusions</label>
                                        <textarea class="control studio-large" name="exclusions" id="crmPackageExclusions"></textarea>
                                    </div>
                                    <div class="field full">
                                        <label>Terms & Important Information</label>
                                        <textarea class="control studio-large" name="terms" id="crmTerms"></textarea>
                                    </div>
                                </div>
                            </details>

                            <details class="studio-section quotation-create-services quote-section-teal" open>
                                <summary><i class="fa-solid fa-suitcase-rolling"></i> Step 3E · Hotels, Vehicle & Other Services</summary>
                                <div class="studio-section-body">
                                    <?php if ($selectedTrip): ?>
                                        <div class="quotation-create-service-note">
                                            <div>
                                                <strong>Version-owned service plan</strong>
                                                <span>Save this quotation and TRAVSCOPE will build destination-wise hotels and city-wise transport from the selected Supplier Contract. Other Trip services are frozen into the new quotation version.</span>
                                            </div>
                                            <div class="quotation-create-service-links">
                                                <?php if (!empty($selectedTrip['package_id'])): ?><a href="admin-package-suppliers.php?package_id=<?= (int)$selectedTrip['package_id']; ?>"><i class="fa-solid fa-diagram-project"></i> Package Supplier Map</a><?php endif; ?>
                                                <a href="admin-trip-contract-rates.php?trip_id=<?= (int)$selectedTrip['id']; ?>"><i class="fa-solid fa-table-cells"></i> Choose Supplier Rate</a>
                                                <a href="admin-supplier-requests.php?lead=<?= (int)$selectedLead['id']; ?>&trip=<?= (int)$selectedTrip['id']; ?>"><i class="fa-solid fa-handshake"></i> Supplier Sourcing / RFQ</a>
                                            </div>
                                        </div>

                                        <div class="quotation-build-toggles">
                                            <input type="hidden" name="quotation_auto_build_hotels" value="0">
                                            <label><input type="checkbox" name="quotation_auto_build_hotels" value="1" checked> <span><i class="fa-solid fa-hotel"></i> Build destination-wise hotels after Save</span></label>
                                            <input type="hidden" name="quotation_auto_build_transport" value="0">
                                            <label><input type="checkbox" name="quotation_auto_build_transport" value="1" checked> <span><i class="fa-solid fa-van-shuttle"></i> Build city-wise vehicle / transport after Save</span></label>
                                        </div>

                                        <div class="quotation-trip-services">
                                            <div class="quotation-service-title">
                                                <span><i class="fa-solid fa-list-check"></i> Current Trip Services</span>
                                                <span><?= count($tripServiceRequirements); ?> service<?= count($tripServiceRequirements)===1?'':'s'; ?></span>
                                            </div>
                                            <?php if ($tripServiceRequirements): ?>
                                                <div class="quotation-trip-service-grid">
                                                    <?php foreach ($tripServiceRequirements as $quotationTripService):
                                                        $qServiceType = strtolower((string)($quotationTripService['service_type'] ?? 'other'));
                                                        $qServiceStatus = strtolower((string)($quotationTripService['status'] ?? 'rate_required'));
                                                        $qServiceIcon = $qServiceType==='hotel' ? 'fa-hotel' : ($qServiceType==='transport' ? 'fa-van-shuttle' : ($qServiceType==='flight' ? 'fa-plane' : ($qServiceType==='meal' ? 'fa-utensils' : 'fa-puzzle-piece')));
                                                    ?>
                                                        <article class="quotation-trip-service-card">
                                                            <div class="quotation-trip-service-icon"><i class="fa-solid <?= e($qServiceIcon); ?>"></i></div>
                                                            <div>
                                                                <strong><?= e((string)($quotationTripService['title'] ?: crmServiceRequirementTypeLabel($qServiceType))); ?></strong>
                                                                <small><?= e(crmServiceRequirementTypeLabel($qServiceType)); ?><?= !empty($quotationTripService['destination_name']) ? ' · '.e((string)$quotationTripService['destination_name']) : ''; ?> · <?= e(crmServiceRequirementStatusLabel($qServiceStatus)); ?></small>
                                                                <?php if (!empty($quotationTripService['specifications'])): ?><p><?= e((string)$quotationTripService['specifications']); ?></p><?php endif; ?>
                                                            </div>
                                                        </article>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="quotation-service-empty">No Trip services are defined yet. Hotels can still be derived from the itinerary; add transport / transfer / sightseeing / activity / meal / flight / guide / visa / insurance / cruise services when required.</div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="quotation-create-service-footer">
                                            <span><i class="fa-solid fa-circle-info"></i> Exact hotel and vehicle choices are edited immediately after the version is saved. Hotels can be selected from Hotel Master Pro or supplier contract inventory; transport remains supplier-contract based.</span>
                                            <a class="button blue" href="#crm-costing"><i class="fa-solid fa-plus"></i> Add / Update Trip Services</a>
                                        </div>
                                    <?php else: ?>
                                        <div class="quotation-service-empty">Create/open the CRM Trip first. Hotel, vehicle and other services belong to the Trip and are then frozen into each quotation version.</div>
                                    <?php endif; ?>
                                </div>
                            </details>

                            <details class="studio-section quote-section-indigo" open>
                                <summary><i class="fa-solid fa-calculator"></i> Step 4 · Supplier Cost, Markup & Final Price</summary>
                                <div class="studio-section-body">
                                    <div class="section-intro">
                                        <strong>Clear costing order:</strong> Package Master = what TRAVSCOPE sells; Supplier Map = who can supply it; Supplier Rate Finder/RFQ = internal purchase cost; markup/discount = customer selling price.
                                        Use <strong>Complete Package / DMC</strong> for one package supplier, or <strong>Service-wise / Hybrid</strong> when hotels, transport and activities use different suppliers.
                                    </div>

                                    <div class="form-grid">
                                        <div class="field">
                                            <label>Travel Date</label>
                                            <input class="control" type="date" name="package_travel_date" id="crmTravelDate" value="<?= e((string)$selectedLead['travel_date']); ?>">
                                        </div>

                                        <div class="field">
                                            <label>Hotel Category / Rate</label>
                                            <select class="control" name="pricing_hotel_category" id="crmPricingHotelCategory">
                                                <option value="3">3 Star</option>
                                                <option value="4" selected>4 Star</option>
                                                <option value="5">5 Star</option>
                                            </select>
                                        </div>

                                        <div class="field">
                                            <label>Adults</label>
                                            <input class="control" type="number" min="1" max="50" name="package_adults" id="crmPricingAdults" value="<?= max(1, (int)$selectedLead['adults']); ?>">
                                        </div>

                                        <div class="field">
                                            <label>Children</label>
                                            <input class="control" type="number" min="0" max="20" name="package_children" id="crmPricingChildren" value="<?= max(0, (int)$selectedLead['children']); ?>">
                                        </div>

                                        <div class="field full" id="crmChildAgesField">
                                            <label>Child Ages</label>
                                            <div id="crmChildAgeInputs" class="crm-child-age-inputs"></div>
                                            <small class="muted">Each child is matched automatically to the Package Master age-wise child price.</small>
                                        </div>
                                    </div>

                                    <style>
                                    .crm-pricing-workspace-head{margin:14px 0 10px;padding:12px 14px;border:1px solid #dbe3ef;border-radius:12px;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;gap:12px}
                                    .crm-pricing-workspace-head strong{display:block;color:#0f172a;font-size:14px}.crm-pricing-workspace-head span{display:block;margin-top:3px;color:#64748b;font-size:11px}.crm-pricing-workspace-head .hint{margin:0;padding:7px 10px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:10px;font-weight:900;white-space:nowrap}
                                    .crm-simple-price-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:10px 0 12px}
                                    .crm-price-tile{appearance:none;width:100%;min-height:112px;text-align:left;border:1px solid #dbe3ef;border-radius:14px;padding:13px 14px;background:#fff;cursor:pointer;box-shadow:0 4px 14px rgba(15,23,42,.04);transition:.18s ease;position:relative}
                                    .crm-price-tile:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(15,23,42,.08)}.crm-price-tile.active{box-shadow:0 0 0 3px rgba(37,99,235,.13),0 10px 24px rgba(15,23,42,.08)}
                                    .crm-price-tile .tile-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}.crm-price-tile .tile-label{font-size:10px;font-weight:900;letter-spacing:.05em}.crm-price-tile .tile-icon{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;color:#fff;font-size:12px}
                                    .crm-price-tile strong{display:block;font-size:18px;line-height:1.25;margin-bottom:5px}.crm-price-tile small{display:block;color:#64748b;font-size:10.5px;line-height:1.4}.crm-price-tile .tile-action{margin-top:7px;font-size:10px;font-weight:900}
                                    .crm-price-tile.blue{background:#f8fbff;border-color:#bfdbfe}.crm-price-tile.blue .tile-label,.crm-price-tile.blue strong,.crm-price-tile.blue .tile-action{color:#1d4ed8}.crm-price-tile.blue .tile-icon{background:#2563eb}
                                    .crm-price-tile.amber{background:#fffdf7;border-color:#fde68a}.crm-price-tile.amber .tile-label,.crm-price-tile.amber strong,.crm-price-tile.amber .tile-action{color:#a16207}.crm-price-tile.amber .tile-icon{background:#f59e0b}
                                    .crm-price-tile.violet{background:#fbfaff;border-color:#ddd6fe}.crm-price-tile.violet .tile-label,.crm-price-tile.violet strong,.crm-price-tile.violet .tile-action{color:#6d28d9}.crm-price-tile.violet .tile-icon{background:#7c3aed}
                                    .crm-price-tile.green{background:#f7fffb;border-color:#a7f3d0}.crm-price-tile.green .tile-label,.crm-price-tile.green strong,.crm-price-tile.green .tile-action{color:#047857}.crm-price-tile.green .tile-icon{background:#10b981}
                                    .crm-price-panel{margin:0 0 12px;border:1px solid #dbe3ef;border-radius:14px;background:#fff;box-shadow:0 8px 22px rgba(15,23,42,.05);overflow:hidden}.crm-price-panel[hidden]{display:none!important}.crm-price-panel-head{padding:13px 15px;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;gap:12px}.crm-price-panel-head>div{display:flex;align-items:center;gap:10px}.crm-price-panel-head i{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;color:#fff}.crm-price-panel-head h4{margin:0;color:#0f172a;font-size:13px}.crm-price-panel-head p{margin:3px 0 0;color:#64748b;font-size:10.5px}.crm-price-panel-head .close-panel{border:1px solid #cbd5e1;background:#fff;color:#475569;border-radius:9px;padding:7px 10px;font-size:10px;font-weight:900;cursor:pointer}
                                    .crm-price-module{min-width:0;align-self:start}.crm-price-module.active{grid-column:1/-1;border-radius:16px;box-shadow:0 10px 28px rgba(15,23,42,.08)}.crm-price-module.active .crm-price-tile{border-radius:16px 16px 0 0;min-height:88px;box-shadow:none;transform:none}.crm-price-module .crm-price-panel{margin:0;border-top:0;border-radius:0 0 16px 16px;box-shadow:none}.crm-price-module.active .crm-price-panel{display:block}.crm-price-module:not(.active) .crm-price-panel{display:none!important}.crm-price-module.active .crm-price-tile .tile-action:after{content:'  •  Details open below';font-weight:800}.crm-price-module .crm-price-panel-head{border-top:1px solid #e2e8f0}.crm-price-module.active.blue{background:#f8fbff}.crm-price-module.active.amber{background:#fffdf7}.crm-price-module.active.violet{background:#fbfaff}.crm-price-module.active.green{background:#f7fffb}
                                    .crm-price-panel.package .crm-price-panel-head i{background:#2563eb}.crm-price-panel.supplier .crm-price-panel-head i{background:#f59e0b}.crm-price-panel.profit .crm-price-panel-head i{background:#7c3aed}.crm-price-panel.customer .crm-price-panel-head i{background:#10b981}
                                    .crm-price-panel-body{padding:14px}.crm-price-panel .crm-costing-summary{padding:0}.crm-supplier-costing{margin:0!important;border:0!important;border-radius:0!important;box-shadow:none!important}.crm-admin-note{margin:0 0 12px;padding:11px 13px;border-left:4px solid #f59e0b;border-radius:10px;background:#fff7ed;color:#9a3412;font-size:11px;line-height:1.5}.crm-inline-help{margin-top:7px;padding:9px 11px;border-radius:9px;background:#f8fafc;color:#475569;font-size:10.5px;line-height:1.45}.crm-profit-note{margin-top:9px;padding:10px 11px;border-radius:9px;background:#f5f3ff;color:#5b21b6;font-size:10.5px;font-weight:700}.crm-price-note{margin:10px 0 0;padding:11px 12px;border-radius:10px;background:#f8fafc;color:#334155;font-size:11px;font-weight:700;border:1px solid #e2e8f0}
                                    /* V24.19 — quotation-wide professional colour system */
                                    #qdeskShell .studio-section{box-shadow:0 6px 20px rgba(15,23,42,.035)}
                                    #qdeskShell .studio-section>summary{border-left:5px solid transparent}
                                    #qdeskShell .studio-section .studio-section-body>.form-grid>.field,
                                    #qdeskShell .studio-section .studio-section-body>.field{padding:11px 12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 3px 10px rgba(15,23,42,.025)}
                                    #qdeskShell .studio-section .field>label{display:flex;align-items:center;gap:6px;margin-bottom:7px;font-weight:800;color:#334155}
                                    #qdeskShell .studio-section .field>.control{border-width:1.5px;background:#fff;transition:border-color .16s ease,box-shadow .16s ease,background .16s ease}
                                    #qdeskShell .studio-section .field>.control:focus{background:#fff;box-shadow:0 0 0 3px rgba(59,130,246,.11)}
                                    #qdeskShell .quote-section-blue{border-color:#bfdbfe}.quote-section-blue>summary{background:#eff6ff!important;border-left-color:#2563eb!important}.quote-section-blue>summary i{background:#dbeafe!important;color:#1d4ed8!important}.quote-section-blue .studio-section-body>.form-grid>.field,.quote-section-blue .studio-section-body>.field{background:#f8fbff!important;border-color:#dbeafe!important}.quote-section-blue .control{border-color:#bfdbfe!important}
                                    #qdeskShell .quote-section-violet{border-color:#ddd6fe}.quote-section-violet>summary{background:#f5f3ff!important;border-left-color:#7c3aed!important}.quote-section-violet>summary i{background:#ede9fe!important;color:#6d28d9!important}.quote-section-violet .studio-section-body>.form-grid>.field,.quote-section-violet .studio-section-body>.field{background:#fbfaff!important;border-color:#e9d5ff!important}.quote-section-violet .control{border-color:#ddd6fe!important}
                                    #qdeskShell .quote-section-green{border-color:#bbf7d0}.quote-section-green>summary{background:#ecfdf5!important;border-left-color:#10b981!important}.quote-section-green>summary i{background:#d1fae5!important;color:#047857!important}.quote-section-green .studio-section-body>.form-grid>.field,.quote-section-green .studio-section-body>.field{background:#f7fffb!important;border-color:#d1fae5!important}.quote-section-green .control{border-color:#a7f3d0!important}
                                    #qdeskShell .quote-section-cyan{border-color:#a5f3fc}.quote-section-cyan>summary{background:#ecfeff!important;border-left-color:#06b6d4!important}.quote-section-cyan>summary i{background:#cffafe!important;color:#0e7490!important}.quote-section-cyan .studio-section-body>.form-grid>.field,.quote-section-cyan .studio-section-body>.field{background:#f7feff!important;border-color:#cffafe!important}.quote-section-cyan .control{border-color:#a5f3fc!important}
                                    #qdeskShell .quote-section-orange{border-color:#fed7aa}.quote-section-orange>summary{background:#fff7ed!important;border-left-color:#f97316!important}.quote-section-orange>summary i{background:#ffedd5!important;color:#c2410c!important}.quote-section-orange .studio-section-body>.form-grid>.field,.quote-section-orange .studio-section-body>.field{background:#fffaf5!important;border-color:#ffedd5!important}.quote-section-orange .control{border-color:#fed7aa!important}
                                    #qdeskShell .quote-section-teal{border-color:#99f6e4}.quote-section-teal>summary{background:#f0fdfa!important;border-left-color:#14b8a6!important}.quote-section-teal>summary i{background:#ccfbf1!important;color:#0f766e!important}.quote-section-teal .studio-section-body>.form-grid>.field,.quote-section-teal .studio-section-body>.field{background:#f7fffd!important;border-color:#ccfbf1!important}.quote-section-teal .control{border-color:#99f6e4!important}
                                    #qdeskShell .quote-section-indigo{border-color:#c7d2fe}.quote-section-indigo>summary{background:#eef2ff!important;border-left-color:#4f46e5!important}.quote-section-indigo>summary i{background:#e0e7ff!important;color:#4338ca!important}

                                    /* Each pricing form field has its own colour identity. */
                                    #crmProfitPanel .form-grid{gap:12px}
                                    #crmProfitPanel .field{padding:13px!important;border-radius:13px!important;border:1.5px solid!important;box-shadow:0 5px 15px rgba(15,23,42,.04)!important}
                                    #crmProfitPanel .field:nth-child(1){background:#eff6ff!important;border-color:#93c5fd!important} #crmProfitPanel .field:nth-child(1) label{color:#1d4ed8!important} #crmProfitPanel .field:nth-child(1) .control{border-color:#60a5fa!important;background:#f8fbff!important}
                                    #crmProfitPanel .field:nth-child(2){background:#f5f3ff!important;border-color:#c4b5fd!important} #crmProfitPanel .field:nth-child(2) label{color:#6d28d9!important} #crmProfitPanel .field:nth-child(2) .control{border-color:#a78bfa!important;background:#fbfaff!important}
                                    #crmProfitPanel .field:nth-child(3){background:#ecfdf5!important;border-color:#86efac!important} #crmProfitPanel .field:nth-child(3) label{color:#047857!important} #crmProfitPanel .field:nth-child(3) .control{border-color:#6ee7b7!important;background:#f7fffb!important;color:#047857!important;font-weight:900}
                                    #crmProfitPanel .field:nth-child(4){background:#fff1f2!important;border-color:#fda4af!important} #crmProfitPanel .field:nth-child(4) label{color:#be123c!important} #crmProfitPanel .field:nth-child(4) .control{border-color:#fb7185!important;background:#fff8f8!important}
                                    #crmProfitPanel .field:nth-child(5){background:#fffbeb!important;border-color:#fde68a!important} #crmProfitPanel .field:nth-child(5) label{color:#a16207!important} #crmProfitPanel .field:nth-child(5) .control{border-color:#facc15!important;background:#fffef7!important;font-weight:800}
                                    #crmProfitPanel .crm-profit-note{background:linear-gradient(135deg,#ede9fe,#f5f3ff)!important;border:1px solid #c4b5fd;color:#5b21b6!important}

                                    #crmSupplierPanel .field{padding:12px!important;border:1.5px solid #fed7aa!important;border-radius:12px!important;background:#fffaf5!important} #crmSupplierPanel .field .control{border-color:#fdba74!important;background:#fffdf9!important} #crmSupplierPanel .field label{color:#9a3412!important}
                                    #crmPackagePanel .crm-cost-card{border:1px solid #bfdbfe!important;background:#f8fbff!important}.crm-price-panel.package .crm-cost-card strong{color:#1d4ed8}
                                    #crmCustomerPanel .crm-result-item:nth-child(1){background:#eff6ff!important;border-color:#bfdbfe!important} #crmCustomerPanel .crm-result-item:nth-child(1) strong{color:#1d4ed8!important}
                                    #crmCustomerPanel .crm-result-item:nth-child(2){background:#f5f3ff!important;border-color:#ddd6fe!important} #crmCustomerPanel .crm-result-item:nth-child(2) strong{color:#6d28d9!important}
                                    #crmCustomerPanel .crm-result-item:nth-child(3){background:#fff1f2!important;border-color:#fecdd3!important} #crmCustomerPanel .crm-result-item:nth-child(3) strong{color:#be123c!important}
                                    #crmCustomerPanel .crm-result-item.final{background:linear-gradient(135deg,#dcfce7,#ecfdf5)!important;border-color:#86efac!important;box-shadow:0 6px 18px rgba(16,185,129,.12)} #crmCustomerPanel .crm-result-item.final strong{color:#047857!important}
                                    #crmCustomerPanel .crm-result-source{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px}
                                    #crmCustomerNotes{border-color:#93c5fd!important;background:#f8fbff!important}
                                    @media(max-width:700px){#qdeskShell .studio-section .studio-section-body>.form-grid>.field,#qdeskShell .studio-section .studio-section-body>.field{padding:10px}}

                                    .crm-customer-result-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.crm-result-item{padding:11px 12px;border:1px solid #e2e8f0;border-radius:11px;background:#f8fafc}.crm-result-item span{display:block;color:#64748b;font-size:9px;font-weight:900;letter-spacing:.04em;margin-bottom:5px}.crm-result-item strong{color:#0f172a;font-size:15px}.crm-result-item.final{background:#ecfdf5;border-color:#a7f3d0}.crm-result-item.final strong{color:#047857}.crm-result-source{margin-top:10px;padding:10px 12px;border-radius:10px;background:#eff6ff;color:#1e40af;font-size:10.5px}.crm-result-actions{display:flex;justify-content:flex-end;margin-top:11px}.crm-result-actions button{border:0;border-radius:10px;padding:10px 14px;background:#10b981;color:#fff;font-size:11px;font-weight:900;cursor:pointer}
                                    @media(max-width:1100px){.crm-simple-price-summary,.crm-customer-result-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.crm-pricing-workspace-head{align-items:flex-start;flex-direction:column}.crm-pricing-workspace-head .hint{white-space:normal}.crm-simple-price-summary,.crm-customer-result-grid{grid-template-columns:1fr}}
                                    </style>

                                    <div class="crm-pricing-workspace-head">
                                        <div><strong>Quotation Pricing</strong><span>Click a price box to open its related form directly underneath that same box. Only one pricing form stays open at a time.</span></div>
                                        <div class="hint"><i class="fa-solid fa-hand-pointer"></i> One box = one form</div>
                                    </div>

                                    <div class="crm-simple-price-summary">
                                        <button type="button" class="crm-price-tile blue" data-pricing-open="crmPackagePanel">
                                            <span class="tile-top"><span class="tile-label">PACKAGE COST</span><span class="tile-icon"><i class="fa-solid fa-suitcase-rolling"></i></span></span>
                                            <strong id="crmPackageAutoTotal">Rate not configured</strong>
                                            <small>Automatic Package Master price based on selected rate, adults and child ages.</small>
                                            <span class="tile-action">View price breakdown →</span>
                                        </button>
                                        <button type="button" class="crm-price-tile amber" data-pricing-open="crmSupplierPanel">
                                            <span class="tile-top"><span class="tile-label">SUPPLIER COST</span><span class="tile-icon"><i class="fa-solid fa-building"></i></span></span>
                                            <strong id="crmSupplierCostDisplay">Not selected</strong>
                                            <small>View approved supplier cost or enter a manual supplier payable amount.</small>
                                            <span class="tile-action">Open supplier costing →</span>
                                        </button>
                                        <button type="button" class="crm-price-tile violet" data-pricing-open="crmProfitPanel">
                                            <span class="tile-top"><span class="tile-label">TRAVSCOPE PROFIT</span><span class="tile-icon"><i class="fa-solid fa-chart-line"></i></span></span>
                                            <strong id="crmProfitDisplay">₹0.00</strong>
                                            <small>Set commission and optional customer discount without showing supplier controls.</small>
                                            <span class="tile-action">Edit profit & discount →</span>
                                        </button>
                                        <button type="button" class="crm-price-tile green" data-pricing-open="crmCustomerPanel">
                                            <span class="tile-top"><span class="tile-label">CUSTOMER PRICE</span><span class="tile-icon"><i class="fa-solid fa-receipt"></i></span></span>
                                            <strong id="crmFinalPriceDisplay">Waiting for rate</strong>
                                            <small>Final quotation amount after selected cost, profit and discount.</small>
                                            <span class="tile-action">Review final price →</span>
                                        </button>
                                    </div>

                                    <div class="crm-price-panel package" id="crmPackagePanel" hidden>
                                        <div class="crm-price-panel-head">
                                            <div><i class="fa-solid fa-calculator"></i><section><h4>Package Cost Breakdown</h4><p>Automatic Package Master calculation for the selected passengers.</p></section></div>
                                            <button type="button" class="close-panel" data-close-pricing-panel>Close</button>
                                        </div>
                                        <div class="crm-price-panel-body">
                                        <div class="crm-costing-summary">
                                            <div class="crm-cost-card"><span>Adult Rate</span><strong id="crmAdultUnitRate">—</strong><small>Travel date + hotel category</small></div>
                                            <div class="crm-cost-card"><span>Adults Total</span><strong id="crmAdultSubtotal">—</strong><small>Adult rate × adults</small></div>
                                            <div class="crm-cost-card"><span>Children Total</span><strong id="crmChildSubtotal">—</strong><small>Matched by child age</small></div>
                                        </div>
                                        </div>
                                    </div>

                                    <div class="crm-price-panel supplier" id="crmSupplierPanel" hidden>
                                        <div class="crm-price-panel-head">
                                            <div><i class="fa-solid fa-building"></i><section><h4>Supplier Cost Source</h4><p>Review or enter the amount TRAVSCOPE will actually pay the supplier.</p></section></div>
                                            <button type="button" class="close-panel" data-close-pricing-panel>Close</button>
                                        </div>
                                        <div class="crm-price-panel-body">
                                            <div class="crm-supplier-costing">
                                            <div class="crm-admin-note"><strong>Which price is used?</strong> If approved supplier quotes are available, TRAVSCOPE uses them first. If not, choose a manual supplier fallback and enter the supplier payable amount. If neither is available, Package Master auto price is used as the base cost.</div>
                                            <div class="crm-costing-title">
                                                <div>
                                                    <span>INTERNAL ONLY</span>
                                                    <h4>Supplier Cost & Admin Pricing</h4>
                                                </div>
                                                <i class="fa-solid fa-lock"></i>
                                            </div>

                                            <input
                                                type="hidden"
                                                id="crmApprovedSupplierCostTotal"
                                                value="<?= e((string)$crmQuotationCostTotal); ?>"
                                            >
                                            <input
                                                type="hidden"
                                                id="crmApprovedSupplierCostMode"
                                                value="<?= e((string)$crmQuotationCostMode); ?>"
                                            >
                                            <input
                                                type="hidden"
                                                id="crmApprovedSupplierCostModeLabel"
                                                value="<?= e((string)$crmQuotationCostModeLabel); ?>"
                                            >
                                            <input
                                                type="hidden"
                                                id="crmApprovedSupplierCostValid"
                                                value="<?= $crmQuotationCostValid ? '1' : '0'; ?>"
                                            >

                                            <?php if ($crmQuotationCostMode === 'trip_cost_sheet' && $crmQuotationCostTotal > 0): ?>
                                                <div class="crm-approved-supplier-cost full">
                                                    <div class="crm-approved-cost-head">
                                                        <div>
                                                            <span>AUTHORITATIVE SUPPLIER COST</span>
                                                            <strong>Trip Cost Sheet</strong>
                                                        </div>
                                                        <div class="crm-approved-total">
                                                            <?= e($crmQuotationCostCurrency); ?>
                                                            <?= number_format($crmQuotationCostTotal, 2); ?>
                                                        </div>
                                                    </div>
                                                    <div class="crm-approved-cost-grid">
                                                        <div><span>SUPPLIER / CONTRACT</span><strong><?= e($crmQuotationCostSupplierNames ? implode(', ', $crmQuotationCostSupplierNames) : 'Trip Cost Sheet'); ?></strong></div>
                                                        <div class="total"><span>SUPPLIER PAYABLE</span><strong><?= e($crmQuotationCostCurrency); ?> <?= number_format($crmQuotationCostTotal,2); ?></strong></div>
                                                    </div>
                                                    <small class="muted">Applied supplier contract/package rates and other Trip Cost Sheet items are automatically used by the quotation pricing calculator.</small>
                                                </div>
                                            <?php elseif (!empty($crmApprovedSupplierQuotes)): ?>
                                                <div class="crm-approved-supplier-cost full">
                                                    <div class="crm-approved-cost-head">
                                                        <div>
                                                            <span>SELECTED SUPPLIER COSTING</span>
                                                            <strong><?= e((string)$crmApprovedSupplierCosting['mode_label']); ?></strong>
                                                        </div>
                                                        <div class="crm-approved-total">
                                                            <?= e((string)($crmApprovedSupplierCosting['currency'] ?: 'INR')); ?>
                                                            <?= number_format((float)$crmApprovedSupplierCosting['total_supplier_cost'], 2); ?>
                                                        </div>
                                                    </div>

                                                    <?php if (empty($crmApprovedSupplierCosting['valid'])): ?>
                                                        <div class="crm-costing-warning">
                                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                                            <?= e((string)$crmApprovedSupplierCosting['error']); ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="crm-approved-cost-grid">
                                                        <div><span>NET / SERVICES</span><strong><?= e((string)($crmApprovedSupplierCosting['currency'] ?: 'INR')); ?> <?= number_format((float)$crmApprovedSupplierCosting['net_amount'],2); ?></strong></div>
                                                        <div><span>SUPPLIER GST / TAX</span><strong><?= e((string)($crmApprovedSupplierCosting['currency'] ?: 'INR')); ?> <?= number_format((float)$crmApprovedSupplierCosting['tax_amount'],2); ?></strong></div>
                                                        <div><span>OTHER / UPGRADE</span><strong><?= e((string)($crmApprovedSupplierCosting['currency'] ?: 'INR')); ?> <?= number_format((float)$crmApprovedSupplierCosting['other_amount'],2); ?></strong></div>
                                                        <div class="total"><span>SUPPLIER PAYABLE</span><strong><?= e((string)($crmApprovedSupplierCosting['currency'] ?: 'INR')); ?> <?= number_format((float)$crmApprovedSupplierCosting['total_supplier_cost'],2); ?></strong></div>
                                                    </div>

                                                    <details class="crm-selected-quote-details">
                                                        <summary>View selected supplier cost sources (<?= (int)$crmApprovedSupplierCosting['quote_count']; ?>)</summary>
                                                        <?php foreach ($crmApprovedSupplierQuotes as $approvedQuote): ?>
                                                            <div class="crm-selected-quote-row">
                                                                <div>
                                                                    <strong><?= e((string)$approvedQuote['company_name']); ?></strong>
                                                                    <small>
                                                                        <?= e((string)$approvedQuote['request_code']); ?>
                                                                        · V<?= (int)$approvedQuote['version_number']; ?>
                                                                        · <?= e(ucwords(str_replace('_',' ',(string)$approvedQuote['costing_mode']))); ?>
                                                                    </small>
                                                                </div>
                                                                <b><?= e((string)$approvedQuote['currency']); ?> <?= number_format((float)$approvedQuote['total_supplier_cost'],2); ?></b>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </details>

                                                    <small class="muted">
                                                        Selected Supplier Sourcing quotes are authoritative. Change them from Supplier Sourcing instead of retyping supplier cost here.
                                                    </small>
                                                </div>

                                                <input type="hidden" name="costing_supplier_id" id="crmCostingSupplier" value="">
                                                <input type="hidden" name="supplier_rate" id="crmSupplierRate" value="0">
                                            <?php else: ?>
                                                <div class="form-grid full crm-manual-supplier-fallback">
                                                    <div class="field">
                                                        <label>Choose Supplier (Manual Fallback)</label>
                                                        <select class="control" name="costing_supplier_id" id="crmCostingSupplier">
                                                            <option value="">Use Package Master Price</option>
                                                            <?php foreach ($crmSupplierOptions as $supplier): ?>
                                                                <option
                                                                    value="<?= (int)$supplier['id']; ?>"
                                                                    data-destination-ids="<?= e((string)($supplier['destination_ids'] ?? '')); ?>"
                                                                    data-currency="<?= e((string)($supplier['default_currency'] ?? 'INR')); ?>"
                                                                >
                                                                    <?= e((string)$supplier['company_name']); ?>
                                                                    <?= !empty($supplier['supplier_code']) ? ' — ' . e((string)$supplier['supplier_code']) : ''; ?>
                                                                    <?= !empty($supplier['priority']) ? ' (' . e(ucfirst((string)$supplier['priority'])) . ')' : ''; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <small class="muted">Use this only when no approved Supplier Sourcing quote is selected. If you select a supplier here, also enter the total supplier payable amount on the right.</small>
                                                    </div>

                                                    <div class="field">
                                                        <label>Enter Total Supplier Payable Amount</label>
                                                        <input class="control" type="number" min="0" step="0.01" name="supplier_rate" id="crmSupplierRate" value="0" placeholder="Example: 65000 for total supplier payable">
                                                        <div class="crm-inline-help">Enter the total amount TRAVSCOPE has to pay the supplier for this quotation. This is an internal cost and is not shown to the customer.</div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            </div>
                                        </div>
                                    </div>

                                    <div class="crm-price-panel profit" id="crmProfitPanel" hidden>
                                        <div class="crm-price-panel-head">
                                            <div><i class="fa-solid fa-chart-line"></i><section><h4>Profit & Discount</h4><p>Set the TRAVSCOPE earning and optional customer discount.</p></section></div>
                                            <button type="button" class="close-panel" data-close-pricing-panel>Close</button>
                                        </div>
                                        <div class="crm-price-panel-body">
                                            <div class="crm-supplier-costing">
                                            <div class="form-grid">
                                            <div class="field">
                                                <label>Profit / Commission Type</label>
                                                <select class="control" name="admin_commission_type" id="crmCommissionType" <?= $aclCanEditMarkup ? '' : 'disabled'; ?>>
                                                    <option value="fixed" <?= $crmDefaultMarkupType === 'fixed' ? 'selected' : ''; ?>>Fixed Amount</option>
                                                    <option value="percent" <?= $crmDefaultMarkupType === 'percent' ? 'selected' : ''; ?>>Percentage %</option>
                                                </select>
                                            </div>

                                            <div class="field">
                                                <label>Profit / Commission Value</label>
                                                <input class="control" type="number" min="0" step="0.01" name="admin_commission_value" id="crmCommissionValue" value="<?= e(number_format($crmDefaultMarkupValue, 2, '.', '')); ?>" <?= $aclCanEditMarkup ? '' : 'disabled'; ?>>
                                            </div>

                                            <div class="field">
                                                <label>Your Profit Amount</label>
                                                <input class="control" type="number" min="0" step="0.01" name="admin_commission_amount" id="crmCommissionAmount" value="0" readonly>
                                            </div>

                                            <div class="field">
                                                <label>Customer Discount Amount</label>
                                                <input class="control" type="number" min="0" step="0.01" name="discount_amount" id="crmDiscountAmount" value="0" <?= $aclCanApproveDiscount ? '' : 'disabled'; ?>>
                                            </div>

                                            <div class="field">
                                                <label>Current Price Source</label>
                                                <input class="control" id="crmCostSourceLabel" value="Package Master Auto Price" readonly>
                                                <div class="crm-profit-note">Financial benefit: <strong id="crmProfitMessage">Your current profit is ₹0.00.</strong> Use this panel to clearly understand which cost is used and how much profit TRAVSCOPE will earn from the quotation.</div>
                                            </div>
                                            </div>
                                            </div>
                                        </div>
                                    </div>

                                    <input type="hidden" name="base_price" id="crmBasePrice" value="0">
                                    <input type="hidden" name="package_auto_total" id="crmPackageAutoTotalInput" value="0">
                                    <input type="hidden" name="final_price" id="crmFinalPrice" value="0">

                                    <div class="crm-price-panel customer" id="crmCustomerPanel" hidden>
                                        <div class="crm-price-panel-head">
                                            <div><i class="fa-solid fa-receipt"></i><section><h4>Final Customer Price</h4><p>Review the financial result before saving the quotation.</p></section></div>
                                            <button type="button" class="close-panel" data-close-pricing-panel>Close</button>
                                        </div>
                                        <div class="crm-price-panel-body">
                                            <div class="crm-customer-result-grid">
                                                <div class="crm-result-item"><span>BASE COST USED</span><strong id="crmCustomerBaseDisplay">₹0.00</strong></div>
                                                <div class="crm-result-item"><span>TRAVSCOPE PROFIT</span><strong id="crmCustomerProfitDisplay">₹0.00</strong></div>
                                                <div class="crm-result-item"><span>CUSTOMER DISCOUNT</span><strong id="crmCustomerDiscountDisplay">₹0.00</strong></div>
                                                <div class="crm-result-item final"><span>FINAL CUSTOMER PRICE</span><strong id="crmCustomerFinalDisplay">₹0.00</strong></div>
                                            </div>
                                            <div class="crm-result-source">Price source: <strong id="crmCustomerSourceDisplay">Package Master Auto Price</strong></div>
                                            <div class="crm-price-note" id="crmFinalFormula">Select/package rate first; then profit and discount are calculated automatically.</div>
                                            <div class="crm-result-actions"><button type="button" data-jump-save><i class="fa-solid fa-floppy-disk"></i> Review complete — go to Save Quotation</button></div>
                                        </div>
                                    </div>

                                    <div class="field" style="margin-top:14px">
                                        <label>Private Customer Notes</label>
                                        <textarea class="control" name="customer_notes" id="crmCustomerNotes"></textarea>
                                    </div>
                                </div>
                            </details>

                            <div class="studio-save-bar" id="qdeskSaveBar">
                                <div>
                                    <strong>Step 5 · Save Customer Quotation</strong>
                                    <span>After reviewing cost source, profit, discount and final customer price, click this button to save the complete quotation version.</span>
                                </div>
                                <button class="button blue studio-save-button" type="submit" <?= $aclCanEditMarkup ? '' : 'disabled title="Permission required: Create / Edit Quotation Markup"'; ?>>
                                    <i class="fa-solid fa-floppy-disk"></i>
                                    Save Customer Quotation
                                </button>
                            </div>
                        </form>

                        <aside class="version-panel">
                            <div class="version-panel-head">
                                <div>
                                    <span>QUOTATION OUTPUT</span>
                                    <h3><?= count($packageVersions); ?> Saved Quotation<?= count($packageVersions) === 1 ? '' : 's'; ?></h3>
                                </div>
                            </div>

                            <div class="version-list studio-version-list">
                                <?php if ($packageVersions): ?>
                                    <?php foreach ($packageVersions as $version): ?>
                                        <?php
                                        $snapshot =
                                            crmCustomerPackageSnapshot(
                                                $version['custom_notes']
                                                ?? ''
                                            );
                                        $snapshot = crmHydrateQuotationSnapshot($pdo, $version, $snapshot);
                                        $versionHotelAdminRows = function_exists('tsHAForQuotation')
                                            ? tsHAForQuotation($pdo, (int)$version['id'])
                                            : [];
                                        $versionTransportAdminRows = function_exists('tsTAForQuotation')
                                            ? tsTAForQuotation($pdo, (int)$version['id'])
                                            : [];
                                        $versionQuotationServices = is_array($snapshot['quotation_services'] ?? null)
                                            ? $snapshot['quotation_services']
                                            : [];
                                        $versionOtherServiceRows = array_values(array_filter(
                                            $versionQuotationServices,
                                            static function ($row): bool {
                                                if (!is_array($row)) return false;
                                                $type = strtolower((string)($row['service_type'] ?? 'other'));
                                                return !in_array($type, ['hotel','transport'], true);
                                            }
                                        ));

                                        $internalCosting =
                                            is_array($snapshot['internal_costing'] ?? null)
                                                ? $snapshot['internal_costing']
                                                : [];

                                        $acceptedBooking =
                                            $quotationAcceptanceMap[
                                                (int)$version['id']
                                            ]
                                            ?? null;

                                        $versionSiteName =
                                            function_exists('getSetting')
                                                ? getSetting(
                                                    $pdo,
                                                    'site_name',
                                                    'TRAVSCOPE.COM'
                                                )
                                                : 'TRAVSCOPE.COM';

                                        $versionLeadId =
                                            (int)$selectedLead['id'];

                                        $versionPdfId =
                                            (int)$version['id'];

                                        $versionPdfUrl =
                                            BASE_URL
                                            . 'admin-crm.php?lead='
                                            . $versionLeadId
                                            . '&quotation_pdf=1&version='
                                            . $versionPdfId
                                            . '&token='
                                            . rawurlencode(
                                                crmQuotationPublicToken(
                                                    $versionLeadId,
                                                    $versionPdfId
                                                )
                                            );

                                        $versionLeadForMessage = [
                                            'customer_name' =>
                                                (string)$selectedLead['name'],
                                            'customer_email' =>
                                                (string)($selectedLead['email'] ?? ''),
                                            'customer_mobile' =>
                                                (string)($selectedLead['mobile'] ?? ''),
                                            'customer_destination' =>
                                                (string)($selectedLead['destination'] ?? ''),
                                        ];

                                        $versionMessage =
                                            crmProfessionalWhatsAppMessage(
                                                $version,
                                                $versionLeadForMessage,
                                                $snapshot,
                                                $versionSiteName,
                                                $currencySymbol,
                                                $versionPdfUrl
                                            );

                                        $waNumber =
                                            crmWhatsAppNumber(
                                                $selectedLead['mobile']
                                            );

                                        $waUrl =
                                            $waNumber !== ''
                                                ? 'https://wa.me/'
                                                    . $waNumber
                                                    . '?text='
                                                    . rawurlencode($versionMessage)
                                                : '';
                                        ?>
                                        <article class="studio-version-card" id="quotationVersion-<?= (int)$version['id']; ?>">
                                            <div class="studio-version-top">
                                                <span class="version-number">VERSION <?= (int)$version['version_number']; ?></span>
                                                <?php if ($acceptedBooking): ?>
                                                    <span style="display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border-radius:999px;background:#dcfce7;color:#166534;font-size:9px;font-weight:900;">
                                                        <i class="fa-solid fa-circle-check"></i>
                                                        ACCEPTED · <?= e((string)$acceptedBooking['booking_number']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <span class="version-date"><?= e(crmDate($version['created_at'], 'd M Y, h:i A')); ?></span>
                                            </div>
                                            <h4><?= e($version['package_title']); ?></h4>
                                            <div class="version-facts">
                                                <span><i class="fa-regular fa-clock"></i> <?= (int)($snapshot['duration_days'] ?? 0); ?>D / <?= (int)($snapshot['duration_nights'] ?? 0); ?>N</span>
                                                <span><i class="fa-solid fa-route"></i> <?= count($snapshot['itinerary_days'] ?? []); ?> Days</span>
                                                <span><i class="fa-solid fa-calendar-days"></i> <?= count($snapshot['date_prices'] ?? []); ?> Price Rows</span>
                                            </div>
                                            <div class="version-price"><?= e(crmMoney($version['final_price'], $currencySymbol)); ?></div>

                                        <?php if ($aclCanViewSupplierCost && !empty($internalCosting)): ?>
                                            <div class="version-detail" style="margin-top:8px;background:#fff8e8;border-color:#f3ddb2;">
                                                <strong>Internal Costing</strong><br>
                                                Cost Source:
                                                <?php
                                                $versionCostSource=(string)($internalCosting['cost_source']??'package_master');
                                                $versionCostMode=(string)($internalCosting['costing_mode']??'');
                                                $versionCostLabel=
                                                    $versionCostSource==='selected_supplier_quotes'
                                                        ? (
                                                            $versionCostMode==='service_wise'
                                                                ? 'Selected Supplier Quotes · Service-wise'
                                                                : (
                                                                    $versionCostMode==='hybrid'
                                                                        ? 'Selected Supplier Quotes · Hybrid'
                                                                        : 'Selected Supplier Quote · Complete Package'
                                                                )
                                                        )
                                                        : (
                                                            $versionCostSource==='manual_supplier_rate'
                                                                || $versionCostSource==='supplier_rate'
                                                                    ? 'Manual Supplier Rate'
                                                                    : 'Package Master'
                                                        );
                                                ?>
                                                <?= e($versionCostLabel); ?>
                                                <?php if (!empty($internalCosting['supplier_name'])): ?>
                                                    · <?= e((string)$internalCosting['supplier_name']); ?>
                                                <?php endif; ?>
                                                <br>
                                                Supplier Payable:
                                                <?= e(crmMoney((float)($internalCosting['supplier_rate'] ?? 0), $currencySymbol)); ?>
                                                <?php if ((float)($internalCosting['supplier_tax_amount'] ?? 0) > 0): ?>
                                                    · Supplier GST/Tax:
                                                    <?= e(crmMoney((float)$internalCosting['supplier_tax_amount'], $currencySymbol)); ?>
                                                <?php endif; ?>
                                                · Travscope Markup:
                                                <?= e(crmMoney((float)($internalCosting['commission_amount'] ?? 0), $currencySymbol)); ?>
                                            </div>
                                        <?php endif; ?>

                                            <details class="version-detail">
                                                <summary>View saved package details</summary>
                                                <div class="snapshot-summary">
                                                    <p><strong>Type:</strong> <?= e((string)($snapshot['package_type'] ?? '-')); ?></p>
                                                    <p><strong>Code:</strong> <?= e((string)($snapshot['package_code'] ?? '-')); ?></p>
                                                    <p><strong>Short Description:</strong><br><?= nl2br(e((string)($snapshot['short_description'] ?? ''))); ?></p>
                                                    <p><strong>Terms:</strong><br><?= nl2br(e((string)($snapshot['terms'] ?? ''))); ?></p>
                                                </div>
                                            </details>

                                            <details class="quotation-service-editor" <?= (int)($_GET['quote_version'] ?? 0) === (int)$version['id'] ? 'open' : ''; ?>>
                                                <summary>
                                                    <span><i class="fa-solid fa-suitcase-rolling"></i> Stay & Transport</span>
                                                    <span class="quotation-service-badges">
                                                        <span class="quotation-service-badge"><?= count($versionHotelAdminRows); ?> hotel stay<?= count($versionHotelAdminRows)===1?'':'s'; ?></span>
                                                        <span class="quotation-service-badge"><?= count($versionTransportAdminRows); ?> transport row<?= count($versionTransportAdminRows)===1?'':'s'; ?></span>
                                                        <span class="quotation-service-badge"><?= count($versionOtherServiceRows); ?> other service<?= count($versionOtherServiceRows)===1?'':'s'; ?></span>
                                                    </span>
                                                </summary>
                                                <div class="quotation-service-body">
                                                    <?php if (!$versionHotelAdminRows && !$versionTransportAdminRows && !$versionOtherServiceRows): ?>
                                                        <div class="quotation-service-empty quotation-service-build">
                                                            <span>No stay/transport plan exists for this quotation yet. Prepare it from the saved itinerary. Hotel choices come from Hotel Master and the selected supplier's location/category Hotel Options. Supplier package cost still comes only from the seasonal package rate matrix.</span>
                                                            <form method="post">
                                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                                <input type="hidden" name="action" value="quotation_build_services">
                                                                <input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>">
                                                                <input type="hidden" name="version_id" value="<?= (int)$version['id']; ?>">
                                                                <button class="button blue quotation-service-save" type="submit"><i class="fa-solid fa-wand-magic-sparkles"></i> Prepare Stay & Transport</button>
                                                            </form>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="quotation-service-title">
                                                            <span><i class="fa-solid fa-hotel"></i> Hotel Stays <small style="font-weight:600;color:#6e8195">· package cost is category-based, not hotel-priced</small></span>
                                                            <?php if ($selectedTrip): ?><a href="admin-trip-hotel-allocations.php?trip_id=<?= (int)$selectedTrip['id']; ?>">Advanced hotel allocation</a><?php endif; ?>
                                                        </div>
                                                        <?php if ($versionHotelAdminRows): ?>
                                                            <?php foreach ($versionHotelAdminRows as $hotelAllocation):
                                                                $hotelAlternatives = is_array($hotelAllocation['alternatives'] ?? null) ? $hotelAllocation['alternatives'] : [];
                                                                $hotelMasterRows = is_array($hotelAllocation['master_hotels'] ?? null) ? $hotelAllocation['master_hotels'] : [];
                                                                $selectedHotelName = trim((string)($hotelAllocation['hotel_name_snapshot'] ?? $hotelAllocation['canonical_hotel_name'] ?? ''));
                                                                $selectionMode = (string)($hotelAllocation['selection_mode'] ?? '');
                                                                $selectedHotelId = $selectionMode === 'hotel_master' ? (int)($hotelAllocation['hotel_id'] ?? 0) : 0;
                                                                $selectedInventoryId = 0;$selectedOptionId=0;
                                                                if ($selectionMode !== 'hotel_master') {
                                                                    foreach ($hotelAlternatives as $alt) {
                                                                        if (!is_array($alt)) continue;
                                                                        $altName = trim((string)($alt['name'] ?? ''));
                                                                        if ($selectedHotelName !== '' && $altName !== '' && strcasecmp($selectedHotelName, $altName) === 0) {
                                                                            $selectedOptionId=(int)($alt['option_id']??0);
                                                                            $selectedInventoryId = (int)($alt['inventory_id'] ?? 0);
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                            ?>
                                                                <form method="post" class="quotation-service-row">
                                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                                    <input type="hidden" name="action" value="quotation_update_hotel">
                                                                    <input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>">
                                                                    <input type="hidden" name="version_id" value="<?= (int)$version['id']; ?>">
                                                                    <input type="hidden" name="allocation_id" value="<?= (int)$hotelAllocation['id']; ?>">
                                                                    <div class="quotation-service-row-head">
                                                                        <strong><?= e((string)($hotelAllocation['destination_name'] ?? 'Location')); ?> · <?= max(1,(int)($hotelAllocation['nights'] ?? 1)); ?> Night<?= max(1,(int)($hotelAllocation['nights'] ?? 1))===1?'':'s'; ?></strong>
                                                                        <span><?= !empty($hotelAllocation['hotel_category']) ? (int)$hotelAllocation['hotel_category'].'★' : e(ucwords((string)($hotelAllocation['accommodation_type'] ?? 'Hotel'))); ?> · <?= e(ucwords((string)($hotelAllocation['allocation_status'] ?? 'proposed'))); ?></span>
                                                                    </div>
                                                                    <div class="quotation-service-grid" style="grid-template-columns:minmax(260px,2fr) minmax(150px,.7fr) auto;align-items:end;">
                                                                        <div>
                                                                            <label>Hotel Choice</label>
                                                                            <select class="control" name="hotel_choice">
                                                                                <option value="none">Not fixed yet / show alternatives</option>
                                                                                <?php if ($hotelMasterRows): ?>
                                                                                    <optgroup label="TRAVSCOPE Hotel Master">
                                                                                        <?php foreach ($hotelMasterRows as $mh): if(!is_array($mh)) continue;
                                                                                            $masterId=(int)($mh['id']??0);$masterName=trim((string)($mh['hotel_name']??''));if($masterId<=0||$masterName==='')continue;
                                                                                            $masterLoc=trim((string)($mh['location_name']??$mh['city']??''));
                                                                                            $masterLabel=($masterLoc!==''?$masterLoc.' · ':'').$masterName.(!empty($mh['is_preferred'])?' · Preferred':'');
                                                                                        ?>
                                                                                            <option value="master:<?= $masterId; ?>" <?= $selectedHotelId===$masterId?'selected':''; ?>><?= e($masterLabel); ?></option>
                                                                                        <?php endforeach; ?>
                                                                                    </optgroup>
                                                                                <?php endif; ?>
                                                                                <?php if ($hotelAlternatives): ?>
                                                                                    <optgroup label="Supplier Hotel Options · same package category">
                                                                                        <?php foreach ($hotelAlternatives as $alt): if(!is_array($alt)) continue;
                                                                                            $altInventoryId=(int)($alt['inventory_id']??0);$altOptionId=(int)($alt['option_id']??0);$altName=trim((string)($alt['name']??''));if(($altInventoryId<=0&&$altOptionId<=0)||$altName==='')continue;
                                                                                            $choiceValue=$altOptionId>0?'offer:'.$altOptionId:'inv:'.$altInventoryId;
                                                                                            $isSelected=$altOptionId>0?($selectedOptionId===$altOptionId):($selectedInventoryId===$altInventoryId);
                                                                                        ?>
                                                                                            <option value="<?= e($choiceValue); ?>" <?=$isSelected?'selected':''; ?>><?= e($altName); ?></option>
                                                                                        <?php endforeach; ?>
                                                                                    </optgroup>
                                                                                <?php endif; ?>
                                                                            </select>
                                                                            <small style="display:block;margin-top:4px;color:#6e8195;">
                                                                                <?= count($hotelMasterRows); ?> Hotel Master match<?=count($hotelMasterRows)===1?'':'es'?> · <?= count($hotelAlternatives); ?> supplier suggestion<?=count($hotelAlternatives)===1?'':'s'?>
                                                                            </small>
                                                                            <?php if (!$hotelAlternatives && empty($hotelAllocation['source_supplier_id'])): ?>
                                                                                <small style="display:block;margin-top:4px;color:#6e8195;">Supplier Hotel Options appear after you select/apply a supplier package rate, because that identifies the supplier and package category.</small>
                                                                            <?php endif; ?>
                                                                            <?php if (!$hotelMasterRows && !$hotelAlternatives): ?>
                                                                                <small style="display:block;margin-top:6px;color:#a15f00;line-height:1.45;">No hotel matched <b><?= e((string)($hotelAllocation['destination_name'] ?? 'this stay')); ?></b>. Check the itinerary city, Hotel Master, or Supplier → Hotel Options. Do not add hotel prices here.</small>
                                                                                <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:6px"><a class="button" href="admin-hotels.php" style="text-decoration:none">Hotel Master</a><a class="button" href="admin-supplier-hotels.php" style="text-decoration:none">Supplier Hotel Options</a></div>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <div>
                                                                            <label>Status</label>
                                                                            <select class="control" name="allocation_status">
                                                                                <?php foreach(['proposed','requested','confirmed','unavailable','replaced','cancelled'] as $st): ?>
                                                                                    <option value="<?= e($st); ?>" <?= (string)($hotelAllocation['allocation_status']??'proposed')===$st?'selected':''; ?>><?= e(ucwords($st)); ?></option>
                                                                                <?php endforeach; ?>
                                                                            </select>
                                                                        </div>
                                                                        <button class="button blue quotation-service-save" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                                                                    </div>
                                                                    <details class="version-detail" style="margin-top:7px;background:#f9fbfd;">
                                                                        <summary>Room, meal & supplier details</summary>
                                                                        <div class="quotation-service-grid" style="margin-top:8px;">
                                                                            <div><label>Room Type</label><input class="control" name="room_type" value="<?= e((string)($hotelAllocation['room_type']??'')); ?>" placeholder="Deluxe / Standard"></div>
                                                                            <div><label>Meal Plan</label><input class="control" name="meal_plan" value="<?= e((string)($hotelAllocation['meal_plan']??'')); ?>" placeholder="CP / MAP / AP"></div>
                                                                            <div><label>Supplier Confirmation Ref</label><input class="control" name="supplier_confirmation_ref" value="<?= e((string)($hotelAllocation['supplier_confirmation_ref']??'')); ?>" placeholder="Optional"></div>
                                                                            <div><label>Internal Note</label><input class="control" name="supplier_note" value="<?= e((string)($hotelAllocation['supplier_note']??'')); ?>" placeholder="Internal only"></div>
                                                                        </div>
                                                                    </details>
                                                                </form>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <div class="quotation-service-empty">No hotel stays were generated. Check the itinerary overnight city first. Hotel Master and Supplier Package hotel choices are matched from that city.</div>
                                                        <?php endif; ?>

                                                        <div class="quotation-service-title" style="margin-top:10px;">
                                                            <span><i class="fa-solid fa-van-shuttle"></i> City-wise Vehicle / Transport</span>
                                                            <?php if ($selectedTrip): ?><a href="admin-trip-transport-allocations.php?trip_id=<?= (int)$selectedTrip['id']; ?>">Full Transport Allocation</a><?php endif; ?>
                                                        </div>
                                                        <?php if ($versionTransportAdminRows): ?>
                                                            <?php foreach ($versionTransportAdminRows as $transportAllocation):
                                                                $transportAlternatives = is_array($transportAllocation['alternatives'] ?? null) ? $transportAllocation['alternatives'] : [];
                                                            ?>
                                                                <form method="post" class="quotation-service-row">
                                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                                    <input type="hidden" name="action" value="quotation_update_transport">
                                                                    <input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>">
                                                                    <input type="hidden" name="version_id" value="<?= (int)$version['id']; ?>">
                                                                    <input type="hidden" name="allocation_id" value="<?= (int)$transportAllocation['id']; ?>">
                                                                    <div class="quotation-service-row-head">
                                                                        <strong><?= e((string)($transportAllocation['route_label'] ?? 'Transport as per itinerary')); ?></strong>
                                                                        <span><?= e((string)($transportAllocation['destination_name'] ?? '')); ?> · <?= max(1,(int)($transportAllocation['pax_total']??1)); ?> Pax · <?= e(ucwords((string)($transportAllocation['allocation_status']??'proposed'))); ?></span>
                                                                    </div>
                                                                    <div class="quotation-service-grid transport">
                                                                        <div>
                                                                            <label>Vehicle from Supplier City Inventory</label>
                                                                            <select class="control" name="vehicle_rule_id">
                                                                                <option value="0">As per itinerary / pax</option>
                                                                                <?php foreach($transportAlternatives as $alt): if(!is_array($alt))continue;$ruleId=(int)($alt['rule_id']??0);$vehicleName=trim((string)($alt['vehicle_type']??''));if($ruleId<=0||$vehicleName==='')continue; ?>
                                                                                    <option value="<?= $ruleId; ?>" <?= (int)($transportAllocation['source_vehicle_rule_id']??0)===$ruleId?'selected':''; ?>><?= e($vehicleName); ?><?= !empty($alt['city'])?' · '.e((string)$alt['city']):''; ?><?= !empty($alt['pax_min'])||!empty($alt['pax_max'])?' · '.e((string)($alt['pax_min']??'?')).'-'.e((string)($alt['pax_max']??'?')).' pax':''; ?></option>
                                                                                <?php endforeach; ?>
                                                                            </select>
                                                                        </div>
                                                                        <div>
                                                                            <label>Status</label>
                                                                            <select class="control" name="allocation_status">
                                                                                <?php foreach(['proposed','requested','confirmed','unavailable','replaced','cancelled'] as $st): ?>
                                                                                    <option value="<?= e($st); ?>" <?= (string)($transportAllocation['allocation_status']??'proposed')===$st?'selected':''; ?>><?= e(ucwords($st)); ?></option>
                                                                                <?php endforeach; ?>
                                                                            </select>
                                                                        </div>
                                                                        <div><label>Confirmation Ref</label><input class="control" name="supplier_confirmation_ref" value="<?= e((string)($transportAllocation['supplier_confirmation_ref']??'')); ?>" placeholder="Optional"></div>
                                                                    </div>
                                                                    <div class="quotation-service-grid transport" style="margin-top:6px;grid-template-columns:1fr 1fr 1fr;">
                                                                        <div><label>Pickup</label><input class="control" name="pickup_point" value="<?= e((string)($transportAllocation['pickup_point']??'')); ?>" placeholder="Airport / Hotel"></div>
                                                                        <div><label>Pickup Time</label><input class="control" name="pickup_time" value="<?= e((string)($transportAllocation['pickup_time']??'')); ?>" placeholder="11:30 AM"></div>
                                                                        <div><label>Drop</label><input class="control" name="drop_point" value="<?= e((string)($transportAllocation['drop_point']??'')); ?>" placeholder="Hotel / Airport"></div>
                                                                    </div>
                                                                    <input type="hidden" name="driver_name" value="<?= e((string)($transportAllocation['driver_name']??'')); ?>">
                                                                    <input type="hidden" name="driver_mobile" value="<?= e((string)($transportAllocation['driver_mobile']??'')); ?>">
                                                                    <input type="hidden" name="vehicle_number" value="<?= e((string)($transportAllocation['vehicle_number']??'')); ?>">
                                                                    <input type="hidden" name="supplier_note" value="<?= e((string)($transportAllocation['supplier_note']??'')); ?>">
                                                                    <div style="display:flex;justify-content:flex-end;margin-top:6px;"><button class="button blue quotation-service-save" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Vehicle</button></div>
                                                                </form>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <div class="quotation-service-empty">No transport rows were generated. Add a transport service to the Trip / Package Services and keep the vehicle in Supplier city-wise inventory.</div>
                                                        <?php endif; ?>

                                                        <div class="quotation-service-title" style="margin-top:10px;">
                                                            <span><i class="fa-solid fa-puzzle-piece"></i> Other Quotation Services</span>
                                                            <?php if ($selectedTrip): ?><a href="#crm-costing">Add / Update Trip Services</a><?php endif; ?>
                                                        </div>
                                                        <?php if ($versionOtherServiceRows): ?>
                                                            <div class="quotation-other-services">
                                                                <?php foreach ($versionOtherServiceRows as $otherService): ?>
                                                                    <div class="quotation-other-service-row">
                                                                        <div>
                                                                            <strong><?= e((string)($otherService['title'] ?: ($otherService['service_label'] ?? 'Service'))); ?></strong>
                                                                            <small><?= e((string)($otherService['service_label'] ?? ucwords(str_replace('_',' ',(string)($otherService['service_type'] ?? 'other'))))); ?><?= !empty($otherService['destination_name']) ? ' · '.e((string)$otherService['destination_name']) : ''; ?></small>
                                                                        </div>
                                                                        <span><?= e(ucwords(str_replace('_',' ',(string)($otherService['status'] ?? 'rate_required')))); ?></span>
                                                                        <?php if (!empty($otherService['specifications'])): ?><p><?= e((string)$otherService['specifications']); ?></p><?php endif; ?>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <div class="quotation-service-empty" style="margin-top:7px;">Other services are frozen into this quotation version. Change Trip services and create a new version to preserve quotation history.</div>
                                                        <?php else: ?>
                                                            <div class="quotation-service-empty">No additional transfer / sightseeing / activity / flight / meal / guide / visa / insurance / cruise service is saved in this version.</div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </details>

                                            <div class="version-actions">
                                                <a class="action" style="background:#eef6ff;color:#0b65c2;border-color:#cfe0f7;" href="<?= e(BASE_URL . 'admin-crm.php?lead=' . (int)$selectedLead['id'] . '&quote_version=' . (int)$version['id'] . '#quotationVersion-' . (int)$version['id']); ?>"><i class="fa-solid fa-hotel"></i> Configure Services</a>
                                                <form method="post">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="send_package_email">
                                                    <input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>">
                                                    <input type="hidden" name="version_id" value="<?= (int)$version['id']; ?>">
                                                    <button class="action" type="submit" <?= (empty($selectedLead['email']) || !$aclCanSendQuotation) ? 'disabled' : ''; ?> title="<?= $aclCanSendQuotation ? 'Send quotation by email' : 'Permission required: Send Customer Quotation'; ?>">
                                                        <i class="fa-regular fa-envelope"></i> Email
                                                    </button>
                                                </form>
                                                <?php if ($waUrl !== '' && $aclCanSendQuotation): ?>
                                                    <a class="action whatsapp-action" target="_blank" rel="noopener" href="<?= e($waUrl); ?>">
                                                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                                    </a>
                                                <?php elseif ($waUrl !== ''): ?>
                                                    <span class="action" style="opacity:.55;cursor:not-allowed;" title="Permission required: Send Customer Quotation">
                                                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                                                    </span>
                                                <?php endif; ?>

                                                <a
                                                    class="action quotation-pdf-action"
                                                    target="_blank"
                                                    rel="noopener"
                                                    href="<?= e(BASE_URL . 'admin-crm.php?lead=' . (int)$selectedLead['id'] . '&quotation_preview=1&version=' . (int)$version['id']); ?>"
                                                >
                                                    <i class="fa-solid fa-file-pdf"></i> PDF Preview
                                                </a>

                                                <?php if ($acceptedBooking): ?>
                                                    <a
                                                        class="action"
                                                        style="background:#ecfdf5;color:#047857;border-color:#a7f3d0;"
                                                        href="<?= e(BASE_URL . 'admin-booking-details.php?id=' . (int)$acceptedBooking['booking_id']); ?>"
                                                    >
                                                        <i class="fa-solid fa-suitcase"></i> Open Booking
                                                    </a>
                                                <?php elseif ($bookingBridgeReady && $selectedTrip): ?>
                                                    <details style="width:100%;margin-top:7px;border:1px solid #bbf7d0;border-radius:10px;background:#f0fdf4;padding:7px;">
                                                        <summary style="cursor:pointer;color:#166534;font-size:10px;font-weight:900;list-style:none;">
                                                            <i class="fa-solid fa-circle-check"></i>
                                                            Customer Accepted → Create Booking
                                                        </summary>
                                                        <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:8px;" onsubmit="return confirm('Confirm customer acceptance of this exact quotation version and create the Booking?');">
                                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                                            <input type="hidden" name="action" value="accept_quotation_create_booking">
                                                            <input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>">
                                                            <input type="hidden" name="trip_id" value="<?= (int)$selectedTrip['id']; ?>">
                                                            <input type="hidden" name="version_id" value="<?= (int)$version['id']; ?>">

                                                            <select class="control" name="accepted_source" style="min-height:34px;font-size:10px;">
                                                                <option value="email">Accepted by Email</option>
                                                                <option value="whatsapp">Accepted by WhatsApp</option>
                                                                <option value="phone">Accepted by Phone</option>
                                                                <option value="in_person">Accepted In Person</option>
                                                                <option value="admin">Admin Confirmed</option>
                                                                <option value="other">Other</option>
                                                            </select>

                                                            <input
                                                                class="control"
                                                                name="customer_confirmation_note"
                                                                placeholder="Optional acceptance reference / note"
                                                                style="min-height:34px;font-size:10px;"
                                                            >

                                                            <button class="button green" type="submit" style="grid-column:1/-1;">
                                                                <i class="fa-solid fa-arrow-right-to-bracket"></i>
                                                                Accept Version <?= (int)$version['version_number']; ?> & Create Booking
                                                            </button>
                                                        </form>
                                                    </details>
                                                <?php elseif (!$bookingBridgeReady): ?>
                                                    <span class="action" style="opacity:.65;cursor:default;">
                                                        <i class="fa-solid fa-database"></i> Booking Bridge SQL Required
                                                    </span>
                                                <?php endif; ?>

                                                <button
                                                    type="button"
                                                    class="action quotation-reply-action"
                                                    data-reply-open
                                                    onclick="if(typeof crmOpenReplyModal==='function'){crmOpenReplyModal(this);} return false;"
                                                    data-lead-id="<?= (int)$selectedLead['id']; ?>"
                                                    data-version-id="<?= (int)$version['id']; ?>"
                                                    data-version-number="<?= (int)$version['version_number']; ?>"
                                                    data-package-title="<?= e((string)$version['package_title']); ?>"
                                                    data-customer-name="<?= e((string)$selectedLead['name']); ?>"
                                                    data-customer-email="<?= e((string)($selectedLead['email'] ?? '')); ?>"
                                                    data-customer-mobile="<?= e((string)($selectedLead['mobile'] ?? '')); ?>"
                                                >
                                                    <i class="fa-solid fa-reply"></i> Reply
                                                </button>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-version-state">
                                        <i class="fa-solid fa-layer-group"></i>
                                        <h4>No customer package yet</h4>
                                        <p>Copy a master package, customize it and save Version 1.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </aside>
                    </div>
                </div>
            </section>

            <section class="card crm-workspace-panel" data-crm-panel="activities" id="activities">
                <div class="card-head"><h2>Activities & Follow-ups</h2></div>
                <div class="card-body activity-layout">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                        <input type="hidden" name="action" value="add_activity">
                        <input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>">
                        <div class="field"><label>Activity Type</label><select class="control" name="activity_type"><?php foreach ($activityTypes as $item): ?><option value="<?= e($item); ?>"><?= e(crmActivityLabel($item)); ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label>Subject</label><input class="control" name="subject" required></div>
                        <div class="field"><label>Details</label><textarea class="control" name="details"></textarea></div>
                        <div class="field"><label>Follow Up Date & Time</label><input class="control" type="datetime-local" name="follow_up_at"></div>
                        <button class="button blue" type="submit"><i class="fa-solid fa-plus"></i> Add Activity</button>
                    </form>

                    <div class="timeline">
                        <?php if ($activities): ?>
                            <?php foreach ($activities as $activity): ?>
                                <article class="activity">
                                    <div class="activity-head">
                                        <div>
                                            <h3><?= e($activity['subject']); ?></h3>
                                            <div class="activity-meta"><?= e(crmActivityLabel($activity['activity_type'])); ?> · <?= e(crmDate($activity['created_at'], 'd M Y, h:i A')); ?></div>
                                        </div>
                                        <?php if (!empty($activity['completed_at'])): ?><span class="badge status-converted">Completed</span><?php endif; ?>
                                    </div>
                                    <?php if (!empty($activity['details'])): ?><p><?= nl2br(e($activity['details'])); ?></p><?php endif; ?>
                                    <?php if (!empty($activity['follow_up_at'])): ?><p><strong>Follow up:</strong> <?= e(crmDate($activity['follow_up_at'], 'd M Y, h:i A')); ?></p><?php endif; ?>
                                    <?php if (empty($activity['completed_at'])): ?>
                                        <form method="post" class="complete">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                                            <input type="hidden" name="action" value="complete_activity">
                                            <input type="hidden" name="lead_id" value="<?= (int)$selectedLead['id']; ?>">
                                            <input type="hidden" name="activity_id" value="<?= (int)$activity['id']; ?>">
                                            <button class="action" type="submit"><i class="fa-solid fa-check"></i> Mark Completed</button>
                                        </form>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="setup"><p>No activity recorded yet.</p></div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!$selectedLead): ?>
        <section class="crm-pipeline-heading">
            <div class="crm-pipeline-title">
                <div class="eyebrow">SALES PIPELINE</div>
                <h2>Lead Pipeline</h2>
                <p>Find a lead, review its stage, then open the relevant customer workspace.</p>
            </div>
            <?php if ($sourceStats): ?>
            <div class="crm-source-ribbon" aria-label="Lead channels">
                <span class="crm-source-heading"><i class="fa-solid fa-chart-simple"></i> CHANNELS</span>
                <?php foreach ($sourceStats as $item): ?>
                <span class="crm-source-chip"><i class="<?= e(crmSourceIcon($item['source'])); ?>"></i><span><?= e(crmSourceLabel($item['source'])); ?></span><strong><?= number_format((int)$item['total']); ?></strong><small><?= number_format((int)$item['converted']); ?> converted</small></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <div class="crm-pipeline-directory">
        <form class="filters crm-pipeline-filters" method="get" role="search" aria-label="Filter CRM leads">
            <label class="crm-sr-only" for="crm-lead-q">Search CRM leads</label>
            <input class="control" id="crm-lead-q" name="q" value="<?= e($q); ?>" placeholder="Search name, mobile, email, campaign, destination or lead ID">
            <label class="crm-sr-only" for="crm-lead-status">Lead status</label>
            <select class="control" id="crm-lead-status" name="status"><option value="">All statuses</option><?php foreach ($statuses as $item): ?><option value="<?= e($item); ?>" <?= $statusFilter === $item ? 'selected' : ''; ?>><?= e(crmStatusLabel($item)); ?></option><?php endforeach; ?></select>
            <label class="crm-sr-only" for="crm-lead-priority">Priority</label>
            <select class="control" id="crm-lead-priority" name="priority"><option value="">All priorities</option><?php foreach ($priorities as $item): ?><option value="<?= e($item); ?>" <?= $priorityFilter === $item ? 'selected' : ''; ?>><?= e(crmPriorityLabel($item)); ?></option><?php endforeach; ?></select>
            <label class="crm-sr-only" for="crm-lead-source">Source</label>
            <select class="control" id="crm-lead-source" name="source"><option value="">All sources</option><?php foreach ($sources as $item): ?><option value="<?= e($item); ?>" <?= $sourceFilter === $item ? 'selected' : ''; ?>><?= e(crmSourceLabel($item)); ?></option><?php endforeach; ?></select>
            <button class="button blue" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
            <?php if ($q !== '' || $statusFilter !== '' || $priorityFilter !== '' || $sourceFilter !== ''): ?><a class="crm-clear-filter" href="<?= e(BASE_URL . 'admin-crm.php'); ?>" aria-label="Clear lead filters"><i class="fa-solid fa-rotate-left"></i> Clear</a><?php endif; ?>
        </form>

        <div class="crm-board-toolbar">
            <span class="crm-pipeline-pill"><i class="fa-solid fa-table-columns"></i> Pipeline board</span>
            <span class="crm-board-count"><strong><?= number_format($totalRows); ?></strong> matching leads <span class="crm-board-cap">· Up to 250 newest records displayed</span></span>
        </div>

        <?php if ($view === 'pipeline'): ?>
            <section class="pipeline" aria-label="Lead pipeline stages">
                <?php foreach ($statuses as $status): ?>
                    <div class="pipe-column">
                        <div class="pipe-head"><span><?= e(crmStatusLabel($status)); ?></span><span><?= count($pipeline[$status] ?? []); ?></span></div>
                        <div class="pipe-body">
                            <?php if (empty($pipeline[$status])): ?><p class="crm-empty-stage"><i class="fa-regular fa-folder-open"></i> No leads in this stage</p><?php endif; ?>
                            <?php foreach (($pipeline[$status] ?? []) as $lead): ?>
                                <article class="lead-card">
                                    <div class="crm-lead-top"><h3 title="<?= e($lead['name']); ?>"><?= e($lead['name']); ?></h3><small>#ENQ<?= str_pad((string)$lead['id'], 5, '0', STR_PAD_LEFT); ?></small></div>
                                    <p class="crm-lead-source"><i class="<?= e(crmSourceIcon($lead['lead_source'])); ?>"></i> <?= e(crmSourceLabel($lead['lead_source'])); ?> <span>· <?= e($lead['destination'] ?: 'Destination pending'); ?></span></p>
                                    <div class="crm-lead-facts"><strong><?= e(crmMoney($lead['estimated_value'], $currencySymbol)); ?></strong><span>Score <?= (int)$lead['lead_score']; ?></span></div>
                                    <?php if (!empty($lead['next_follow_up_at'])): ?><p class="crm-lead-follow"><i class="fa-regular fa-calendar"></i> Follow-up <?= e(crmDate($lead['next_follow_up_at'], 'd M Y')); ?></p><?php endif; ?>
                                    <div class="lead-card-bottom">
                                        <span class="badge priority-<?= e($lead['priority']); ?>"><?= e(crmPriorityLabel($lead['priority'])); ?></span>
                                        <a class="action manage" href="<?= e(BASE_URL . 'admin-crm.php?lead=' . (int)$lead['id']); ?>" aria-label="Open lead <?= (int)$lead['id']; ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <section class="card">
                <div class="card-head"><h2>All Leads (<?= number_format($totalRows); ?>)</h2></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Lead</th><th>Customer</th><th>Source</th><th>Destination</th><th>Score</th><th>Priority</th><th>Status</th><th>Follow Up</th><th>Estimated Value</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if ($leads): ?>
                            <?php foreach ($leads as $lead): ?>
                                <?php $scoreClass = strtolower(crmLeadScoreLabel((int)$lead['lead_score'])); ?>
                                <tr>
                                    <td><a class="strong" href="<?= e(BASE_URL . 'admin-crm.php?lead=' . (int)$lead['id']); ?>">#ENQ<?= str_pad((string)$lead['id'], 5, '0', STR_PAD_LEFT); ?></a><div class="small"><?= e(crmDate($lead['created_at'])); ?></div></td>
                                    <td><div class="strong"><?= e($lead['name']); ?></div><div class="small"><?= e($lead['mobile']); ?></div><div class="small"><?= e($lead['email']); ?></div></td>
                                    <td><span class="source"><i class="<?= e(crmSourceIcon($lead['lead_source'])); ?>"></i> <?= e(crmSourceLabel($lead['lead_source'])); ?></span><div class="small"><?= e($lead['campaign_name'] ?: ''); ?></div></td>
                                    <td><?= e($lead['destination'] ?: '-'); ?><div class="small"><?= e(crmDate($lead['travel_date'])); ?></div></td>
                                    <td><span class="score score-<?= e($scoreClass); ?>"><?= (int)$lead['lead_score']; ?> · <?= e(crmLeadScoreLabel((int)$lead['lead_score'])); ?></span></td>
                                    <td><span class="badge priority-<?= e($lead['priority']); ?>"><?= e(crmPriorityLabel($lead['priority'])); ?></span></td>
                                    <td><span class="badge status-<?= e($lead['status']); ?>"><?= e(crmStatusLabel($lead['status'])); ?></span></td>
                                    <td><?= e(crmDate($lead['next_follow_up_at'], 'd M Y')); ?><div class="small"><?= e(crmDate($lead['next_follow_up_at'], 'h:i A')); ?></div></td>
                                    <td><?= e(crmMoney($lead['estimated_value'], $currencySymbol)); ?></td>
                                    <td><div class="actions">
                                        <?php if (crmWhatsAppNumber($lead['mobile']) !== ''): ?><a class="action" target="_blank" href="https://wa.me/<?= e(crmWhatsAppNumber($lead['mobile'])); ?>"><i class="fa-brands fa-whatsapp"></i></a><?php endif; ?>
                                        <a class="action manage" href="<?= e(BASE_URL . 'admin-crm.php?lead=' . (int)$lead['id']); ?>">Manage</a>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="10" class="setup">No CRM leads found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a class="page-btn <?= $i === $page ? 'active' : ''; ?>" href="<?= e(crmPageUrl($i)); ?>"><?= $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        </div><!-- /.crm-pipeline-directory -->
        <?php endif; // !$selectedLead: keep other leads out of an open Trip/Quotation workspace ?>

    <?php endif; ?>
</main>

</section>
</div>


<style>
/* =====================================================================
   TRAVSCOPE V24.29 — GLOBAL FAIR PHOTO PICKER
   Equal comparison cards + clean pagination across every API photo search.
   ===================================================================== */
#pexelsResults.pexels-results,
#pexelsGrid.pexels-grid,
#crmMediaGrid.crm-media-grid{
    display:grid !important;
    grid-template-columns:repeat(auto-fill,minmax(180px,1fr)) !important;
    grid-auto-flow:row !important;
    grid-auto-rows:1fr !important;
    align-items:stretch !important;
    align-content:start !important;
    gap:14px !important;
    padding:16px !important;
}
#pexelsResults.pexels-results,
#pexelsGrid.pexels-grid{max-height:56vh;overflow-y:auto !important;overflow-x:hidden !important}
.pexels-photo-card,
.pexels-card,
.crm-media-item[data-provider]{
    position:relative !important;
    display:flex !important;
    flex-direction:column !important;
    width:100% !important;
    height:100% !important;
    min-height:310px !important;
    margin:0 !important;
    overflow:hidden !important;
    border:1px solid #dbe4ef !important;
    border-radius:14px !important;
    background:#fff !important;
    box-shadow:0 6px 18px rgba(15,23,42,.07) !important;
    transition:transform .18s ease,border-color .18s ease,box-shadow .18s ease !important;
}
.pexels-photo-card:hover,
.pexels-card:hover,
.crm-media-item[data-provider]:hover{
    transform:translateY(-2px) !important;
    border-color:#93c5fd !important;
    box-shadow:0 12px 28px rgba(37,99,235,.13) !important;
}
.pexels-photo-image,
.pexels-photo-wrap{
    position:relative !important;
    width:100% !important;
    height:158px !important;
    min-height:158px !important;
    aspect-ratio:auto !important;
    overflow:hidden !important;
    background:#eef2f7 !important;
    flex:0 0 158px !important;
}
.pexels-photo-image img,
.pexels-photo-wrap img,
.crm-media-item[data-provider] > img{
    display:block !important;
    width:100% !important;
    height:158px !important;
    min-height:158px !important;
    max-height:158px !important;
    object-fit:cover !important;
    object-position:center !important;
    margin:0 !important;
    padding:0 !important;
    border:0 !important;
}
.pexels-photo-body,
.pexels-card-body,
.crm-media-item[data-provider] .crm-media-item-body{
    display:flex !important;
    flex:1 1 auto !important;
    flex-direction:column !important;
    padding:10px !important;
    background:#fff !important;
}
.pexels-photo-credit,
.crm-media-item[data-provider] .crm-free-credit{
    min-height:30px !important;
    margin:0 0 6px !important;
    padding:0 !important;
    color:#64748b !important;
    font-size:9px !important;
    line-height:1.4 !important;
}
.pexels-photo-name,
.crm-media-item[data-provider] .crm-media-item-name{
    min-height:36px !important;
    margin:0 0 8px !important;
    color:#1e293b !important;
    font-size:11px !important;
    font-weight:800 !important;
    line-height:1.45 !important;
    white-space:normal !important;
    display:-webkit-box !important;
    -webkit-line-clamp:2 !important;
    -webkit-box-orient:vertical !important;
    overflow:hidden !important;
}
.pexels-import-button,
.pexels-card button,
.crm-media-item[data-provider] .crm-media-select{
    width:100% !important;
    min-height:38px !important;
    margin-top:auto !important;
    border:0 !important;
    border-radius:9px !important;
    background:linear-gradient(135deg,#0f766e,#10b981) !important;
    color:#fff !important;
    font-size:10px !important;
    font-weight:900 !important;
    cursor:pointer !important;
}
.free-photo-provider-badge,
.crm-provider-badge{
    border:0 !important;
    color:#fff !important;
    font-weight:900 !important;
    box-shadow:0 4px 12px rgba(15,23,42,.22) !important;
}
.free-photo-provider-badge.pexels,
[data-provider="pexels"] .free-photo-provider-badge,
.crm-provider-badge.pexels{background:#0f766e !important;color:#fff !important}
.free-photo-provider-badge.unsplash,
[data-provider="unsplash"] .free-photo-provider-badge,
.crm-provider-badge.unsplash{background:#111827 !important;color:#fff !important}
.pexels-click-hint{display:none !important}
.pexels-pagination,
.ts-photo-pagination,
.crm-photo-pagination{
    flex:0 0 auto !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:14px !important;
    min-height:58px !important;
    padding:10px 16px !important;
    border-top:1px solid #e2e8f0 !important;
    background:#fff !important;
    color:#64748b !important;
    font-size:12px !important;
}
.pexels-pagination[hidden],
.ts-photo-pagination[hidden],
.crm-photo-pagination[hidden]{display:none !important}
.pexels-pagination button,
.ts-photo-pagination button,
.crm-photo-pagination button{
    min-height:38px !important;
    padding:0 15px !important;
    border:1px solid #d7e0eb !important;
    border-radius:9px !important;
    background:#fff !important;
    color:#183153 !important;
    font-size:11px !important;
    font-weight:900 !important;
    cursor:pointer !important;
}
.pexels-pagination button:hover:not(:disabled),
.ts-photo-pagination button:hover:not(:disabled),
.crm-photo-pagination button:hover:not(:disabled){background:#eff6ff !important;border-color:#93c5fd !important;color:#1d4ed8 !important}
.pexels-pagination button:disabled,
.ts-photo-pagination button:disabled,
.crm-photo-pagination button:disabled{opacity:.42 !important;cursor:not-allowed !important}
.pexels-pagination span,
.ts-photo-pagination span,
.crm-photo-pagination span{min-width:125px;text-align:center;font-weight:800;color:#64748b}
@media(max-width:900px){
    #pexelsResults.pexels-results,#pexelsGrid.pexels-grid,#crmMediaGrid.crm-media-grid{grid-template-columns:repeat(3,minmax(0,1fr)) !important}
}
@media(max-width:620px){
    #pexelsResults.pexels-results,#pexelsGrid.pexels-grid,#crmMediaGrid.crm-media-grid{grid-template-columns:repeat(2,minmax(0,1fr)) !important;padding:10px !important;gap:10px !important}
    .pexels-photo-image,.pexels-photo-wrap,.pexels-photo-image img,.pexels-photo-wrap img,.crm-media-item[data-provider] > img{height:135px !important;min-height:135px !important;max-height:135px !important;flex-basis:135px !important}
}
</style>

<div class="crm-media-modal" id="crmMediaModal" aria-hidden="true">
    <div class="crm-media-dialog">
        <div class="crm-media-head">
            <div><h3 id="crmMediaTitle">Choose Web Image</h3><div class="small" id="crmMediaHelp">Select an existing website image.</div></div>
            <div class="crm-media-head-actions">
                <button type="button" class="button green" id="crmMediaDone" hidden><i class="fa-solid fa-check"></i> Done</button>
                <button type="button" class="crm-media-close" id="crmMediaClose"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
        <div class="crm-media-tools">
            <input class="control" id="crmMediaSearch" placeholder="Search image name...">
            <button type="button" class="button blue" id="crmPexelsSearchButton" hidden><i class="fa-solid fa-magnifying-glass"></i> Search Pexels + Unsplash</button>
        </div>
        <div class="crm-media-grid" id="crmMediaGrid"></div>
        <div class="crm-photo-pagination" id="crmFreePhotoPagination" hidden>
            <button type="button" id="crmFreePhotoPrevious"><i class="fa-solid fa-chevron-left"></i> Previous</button>
            <span id="crmFreePhotoPageText">Page 1</span>
            <button type="button" id="crmFreePhotoNext">Next <i class="fa-solid fa-chevron-right"></i></button>
        </div>
    </div>
</div>

<script type="application/json" id="crmMediaLibraryData"><?= json_encode($crmMediaLibrary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>



<div
    class="crm-reply-modal"
    id="crmReplyModal"
    aria-hidden="true"
>
    <div class="crm-reply-dialog crm-conversation-dialog">

        <div class="crm-reply-head">
            <div>
                <h3>
                    <i class="fa-solid fa-comments"></i>
                    Quotation Conversation
                </h3>

                <p id="crmReplyVersionLabel">
                    Loading...
                </p>
            </div>

            <button
                type="button"
                class="crm-reply-close"
                id="crmReplyClose"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="crm-conversation-customer">
            <div>
                <strong id="crmReplyCustomerName">Customer</strong>
                <span id="crmReplyCustomerEmail"></span>
            </div>

            <button
                type="button"
                class="action"
                id="crmConversationRefresh"
            >
                <i class="fa-solid fa-rotate"></i>
                Refresh Email Replies
            </button>
        </div>

        <div
            class="crm-conversation-status"
            id="crmConversationStatus"
        ></div>

        <div
            class="crm-conversation-thread"
            id="crmConversationThread"
        >
            <div class="crm-conversation-loading">
                <i class="fa-solid fa-circle-notch fa-spin"></i>
                Loading conversation...
            </div>
        </div>

        <div class="crm-conversation-compose">

            <div class="crm-compose-label">
                Reply to this quotation version
            </div>

            <form
                method="post"
                id="crmReplyForm"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken); ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="send_version_reply"
                >

                <input
                    type="hidden"
                    name="lead_id"
                    id="crmReplyLeadId"
                >

                <input
                    type="hidden"
                    name="version_id"
                    id="crmReplyVersionId"
                >

                <textarea
                    class="crm-reply-text"
                    name="reply_message"
                    id="crmReplyMessage"
                    placeholder="Write your reply to this exact quotation version..."
                    required
                ></textarea>

                <div class="crm-reply-actions">

                    <button
                        type="submit"
                        class="button crm-reply-send"
                    >
                        <i class="fa-solid fa-paper-plane"></i>
                        Send Email Reply
                    </button>

                    <a
                        href="#"
                        class="button crm-reply-whatsapp"
                        id="crmReplyWhatsApp"
                        target="_blank"
                        rel="noopener"
                    >
                        <i class="fa-brands fa-whatsapp"></i>
                        WhatsApp
                    </a>

                </div>

            </form>
        </div>

    </div>
</div>

<div class="modal" id="leadModal" aria-hidden="true">
    <div class="modal-box">
        <div class="modal-head"><h2>Add New Lead</h2><button type="button" class="modal-close" id="closeLeadModal"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">
                <input type="hidden" name="action" value="create_lead">
                <div class="form-grid">
                    <div class="field"><label>Customer Name *</label><input class="control" name="name" required></div>
                    <div class="field"><label>Mobile</label><input class="control" name="mobile"></div>
                    <div class="field"><label>Email</label><input class="control" type="email" name="email"></div>
                    <div class="field">
<label>Destination</label>
<select class="control" name="destination_id">
<option value="">Select Destination</option>
<?php foreach ($crmDestinationOptions as $destinationOption): ?>
<option value="<?= (int)$destinationOption['id']; ?>">
<?= e((string)$destinationOption['name']); ?><?= !empty($destinationOption['country']) ? ' · '.e((string)$destinationOption['country']) : ''; ?>
</option>
<?php endforeach; ?>
</select>
</div>
                    <div class="field"><label>Travel Date</label><input class="control" type="date" name="travel_date"></div>
                    <div class="field"><label>Budget</label><input class="control" type="number" min="0" step="0.01" name="budget"></div>
                    <div class="field"><label>Adults</label><input class="control" type="number" min="1" name="adults" value="2"></div>
                    <div class="field"><label>Children</label><input class="control" type="number" min="0" name="children" value="0"></div>
                    <div class="field"><label>Lead Source</label><select class="control" name="lead_source"><?php foreach ($sources as $item): ?><option value="<?= e($item); ?>" <?= $item === 'manual' ? 'selected' : ''; ?>><?= e(crmSourceLabel($item)); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Priority</label><select class="control" name="priority"><?php foreach ($priorities as $item): ?><option value="<?= e($item); ?>" <?= $item === 'medium' ? 'selected' : ''; ?>><?= e(crmPriorityLabel($item)); ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Campaign Name</label><input class="control" name="campaign_name"></div>
                    <div class="field"><label>Lead Score (0–100)</label><input class="control" type="number" min="0" max="100" name="lead_score" value="50"></div>
                    <div class="field"><label>Social Profile Name</label><input class="control" name="social_profile_name"></div>
                    <div class="field"><label>Platform Lead ID</label><input class="control" name="platform_lead_id"></div>
                    <div class="field full"><label>Social Profile URL</label><input class="control" type="url" name="social_profile_url"></div>
                    <div class="field"><label>Next Follow Up</label><input class="control" type="datetime-local" name="next_follow_up_at"></div>
                    <div class="field"><label>Tags</label><input class="control" name="tags"></div>
                    <div class="field full"><label>Customer Requirement</label><textarea class="control" name="message"></textarea></div>
                </div>
                <button class="button blue" type="submit"><i class="fa-solid fa-plus"></i> Create CRM Lead</button>
            </form>
        </div>
    </div>
</div>

<script>

const crmPackageSelect = document.getElementById('crmPackageSelect');
const crmPackageTitle = document.getElementById('crmPackageTitle');
const crmDestinationId = document.getElementById('crmDestinationId');
const crmPackageType = document.getElementById('crmPackageType');
const crmPackageCode = document.getElementById('crmPackageCode');
const crmCurrency = document.getElementById('crmCurrency');
const crmShortDescription = document.getElementById('crmShortDescription');
const crmPackageDescription = document.getElementById('crmPackageDescription');
const crmDurationDays = document.getElementById('crmDurationDays');
const crmDurationNights = document.getElementById('crmDurationNights');
const crmTourHighlights = document.getElementById('crmTourHighlights');
const crmPackageInclusions = document.getElementById('crmPackageInclusions');
const crmPackageExclusions = document.getElementById('crmPackageExclusions');
const crmTerms = document.getElementById('crmTerms');
const crmBasePrice = document.getElementById('crmBasePrice');
const crmDiscountAmount = document.getElementById('crmDiscountAmount');
const crmFinalPrice = document.getElementById('crmFinalPrice');
const crmExistingMainImage = document.getElementById('crmExistingMainImage');
const crmMainImage = document.getElementById('crmMainImage');
const crmMainPreview = document.getElementById('crmMainPreview');
const crmGalleryImages = document.getElementById('crmGalleryImages');
const crmGalleryPreview = document.getElementById('crmGalleryPreview');
const crmExistingGalleryInputs = document.getElementById('crmExistingGalleryInputs');
const crmMasterPriceRows = document.getElementById('crmMasterPriceRows');
const crmMasterChildPrices = document.getElementById('crmMasterChildPrices');
const crmItineraryRows = document.getElementById('crmItineraryRows');

function crmImageUrl(path) {
    if (!path) return '';

    if (/^https?:\/\//i.test(path)) {
        return path;
    }

    /*
    | Pexels-imported local files can have readable names with spaces.
    | Encode each URL path segment so the browser can always display them.
    | The hidden input still keeps the original unencoded database path.
    */
    const encodedPath = String(path)
        .replace(/^\/+/, '')
        .split('/')
        .map(segment => encodeURIComponent(segment))
        .join('/');

    return <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES); ?> + encodedPath;
}

function setMainPreview(path, exactUrl = '') {
    if (!crmMainPreview) return;
    const previewUrl = exactUrl || crmImageUrl(path);
    crmMainPreview.innerHTML = path
        ? '<img src="' + previewUrl + '" alt="Main image">'
        : '<span>No main image selected</span>';
    if (crmExistingMainImage) crmExistingMainImage.value = path || '';
}

function renderGallery(paths) {
    if (!crmGalleryPreview || !crmExistingGalleryInputs) return;
    crmGalleryPreview.innerHTML = '';
    crmExistingGalleryInputs.innerHTML = '';

    (paths || []).forEach(function (path, index) {
        const item = document.createElement('div');
        item.className = 'gallery-preview-item';
        item.innerHTML =
            '<img src="' + crmImageUrl(path) + '" alt="Gallery image">'
            + '<button type="button" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button>';

        item.querySelector('button').addEventListener('click', function () {
            const current = Array.from(
                crmExistingGalleryInputs.querySelectorAll('input')
            ).map(input => input.value).filter(Boolean);
            current.splice(index, 1);
            renderGallery(current);
        });

        crmGalleryPreview.appendChild(item);

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'existing_gallery_images[]';
        input.value = path;
        crmExistingGalleryInputs.appendChild(input);
    });
}


function crmAppendGalleryPreview(path, exactUrl = '') {
    if (!path || !crmGalleryPreview || !crmExistingGalleryInputs) return;

    const current = Array.from(
        crmExistingGalleryInputs.querySelectorAll('input[name="existing_gallery_images[]"]')
    ).map(input => input.value).filter(Boolean);

    if (current.includes(path)) return;

    const index = current.length;
    const item = document.createElement('div');
    item.className = 'gallery-preview-item';
    item.innerHTML =
        '<img src="' + (exactUrl || crmImageUrl(path)) + '" alt="Gallery image">'
        + '<button type="button" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button>';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'existing_gallery_images[]';
    input.value = path;
    crmExistingGalleryInputs.appendChild(input);
    crmGalleryPreview.appendChild(item);

    if (crmMediaDone) {
        const total = crmExistingGalleryInputs.querySelectorAll('input[name="existing_gallery_images[]"]').length;
        crmMediaDone.innerHTML = '<i class="fa-solid fa-check"></i> Done (' + total + ')';
    }

    item.querySelector('button').addEventListener('click', function () {
        input.remove();
        item.remove();
        if (crmMediaDone) {
            const total = crmExistingGalleryInputs.querySelectorAll('input[name="existing_gallery_images[]"]').length;
            crmMediaDone.innerHTML = '<i class="fa-solid fa-check"></i> Done (' + total + ')';
        }
    });
}


function crmMoneyNumber(value) {
    return '₹' + Number(value || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function renderMasterPriceRows(rows) {
    if (!crmMasterPriceRows) return;

    const source = Array.isArray(rows) ? rows : [];

    if (!source.length) {
        crmMasterPriceRows.innerHTML =
            '<tr><td colspan="5" class="muted">No date-wise Package Master price rows found. Sale price will be used as fallback.</td></tr>';
        return;
    }

    crmMasterPriceRows.innerHTML = source.map(function (row) {
        return `
            <tr>
                <td>${row.valid_from || '-'}</td>
                <td>${row.valid_to || '-'}</td>
                <td>${crmMoneyNumber(row.price_3_star)}</td>
                <td>${crmMoneyNumber(row.price_4_star)}</td>
                <td>${crmMoneyNumber(row.price_5_star)}</td>
            </tr>
        `;
    }).join('');
}

function renderMasterChildPrices(rows) {
    if (!crmMasterChildPrices) return;

    const source = Array.isArray(rows) ? rows : [];

    if (!source.length) {
        crmMasterChildPrices.innerHTML =
            '<span class="muted">No age-wise child price rows found for this package.</span>';
        return;
    }

    crmMasterChildPrices.innerHTML = source.map(function (row) {
        return `
            <span class="crm-child-rate-pill">
                Age ${Number(row.age_from || 0)}–${Number(row.age_to || 0)}
                • ${crmMoneyNumber(row.price)}
            </span>
        `;
    }).join('');
}

let crmCurrentPackagePricing = null;

function crmSelectedPackageData() {
    if (!crmPackageSelect) return null;

    const option = crmPackageSelect.options[crmPackageSelect.selectedIndex];
    const raw = option?.dataset?.package || '';

    if (!raw) return null;

    try {
        return JSON.parse(raw);
    } catch (error) {
        return null;
    }
}

function crmRenderChildAgeInputs() {
    const holder = document.getElementById('crmChildAgeInputs');
    const countInput = document.getElementById('crmPricingChildren');

    if (!holder || !countInput) return;

    const count = Math.max(0, Math.min(20, Number(countInput.value || 0)));
    const previous = Array.from(holder.querySelectorAll('[name="child_age[]"]'))
        .map(input => input.value);

    holder.innerHTML = '';

    for (let index = 0; index < count; index++) {
        const wrapper = document.createElement('div');
        wrapper.className = 'field';
        wrapper.innerHTML = `
            <label>Child ${index + 1} Age</label>
            <input
                class="control crm-child-age"
                type="number"
                min="0"
                max="18"
                step="1"
                name="child_age[]"
                value="${previous[index] ?? ''}"
                placeholder="Age"
                required
            >
        `;
        holder.appendChild(wrapper);
    }

    document.getElementById('crmChildAgesField')?.toggleAttribute('hidden', count === 0);

    holder.querySelectorAll('.crm-child-age').forEach(function (input) {
        input.addEventListener('input', calculateCrmFinalPrice);
        input.addEventListener('change', calculateCrmFinalPrice);
    });
}

function crmFilterSuppliersForDestination(destinationId) {
    const supplier = document.getElementById('crmCostingSupplier');

    if (!supplier || !supplier.options) return;

    const destination = String(destinationId || '');

    Array.from(supplier.options).forEach(function (option, index) {
        if (index === 0) {
            option.disabled = false;
            option.hidden = false;
            return;
        }

        const ids = String(option.dataset.destinationIds || '')
            .split(',')
            .map(value => value.trim())
            .filter(Boolean);

        const allowed = destination === '' || ids.includes(destination);

        option.disabled = !allowed;
        option.hidden = !allowed;
    });

    if (supplier.selectedOptions[0]?.disabled) {
        supplier.value = '';
        document.getElementById('crmSupplierRate').value = '0';
    }
}

function crmMatchedAdultRate(pkg, travelDate, star) {
    if (!pkg) return 0;

    const rows = Array.isArray(pkg.date_prices) ? pkg.date_prices : [];
    const key = `price_${star}_star`;

    const matching = rows.find(function (row) {
        if (!travelDate || !row.valid_from || !row.valid_to) return false;
        return travelDate >= row.valid_from && travelDate <= row.valid_to;
    });

    if (
        matching
        && matching[key] !== null
        && matching[key] !== ''
        && Number(matching[key] || 0) > 0
    ) {
        return Number(matching[key] || 0);
    }

    return Number(pkg.sale_price || 0);
}

function crmMatchedChildRate(pkg, age) {
    if (!pkg) return 0;

    const rows = Array.isArray(pkg.child_prices) ? pkg.child_prices : [];
    const numericAge = Number(age);

    const matching = rows.find(function (row) {
        return numericAge >= Number(row.age_from || 0)
            && numericAge <= Number(row.age_to || 0);
    });

    return matching ? Number(matching.price || 0) : 0;
}

function calculateCrmFinalPrice() {
    const pkg = crmCurrentPackagePricing || crmSelectedPackageData();

    const travelDate = document.getElementById('crmTravelDate')?.value || '';
    const star = document.getElementById('crmPricingHotelCategory')?.value || '4';
    const adults = Math.max(1, Number(document.getElementById('crmPricingAdults')?.value || 1));

    const adultRate = crmMatchedAdultRate(pkg, travelDate, star);
    const adultsTotal = adultRate * adults;

    let childrenTotal = 0;

    document.querySelectorAll('[name="child_age[]"]').forEach(function (input) {
        if (input.value !== '') {
            childrenTotal += crmMatchedChildRate(pkg, input.value);
        }
    });

    const packageTotal = adultsTotal + childrenTotal;

    const approvedSupplierTotal =
        Math.max(
            0,
            Number(
                document.getElementById('crmApprovedSupplierCostTotal')?.value
                || 0
            )
        );

    const approvedSupplierModeLabel =
        document.getElementById('crmApprovedSupplierCostModeLabel')?.value
        || '';

    const approvedSupplierValid =
        (document.getElementById('crmApprovedSupplierCostValid')?.value || '1') === '1';

    const supplierSelect = document.getElementById('crmCostingSupplier');
    const supplierRateInput = document.getElementById('crmSupplierRate');
    const supplierRate = Math.max(0, Number(supplierRateInput?.value || 0));
    const supplierSelected =
        supplierSelect
        && supplierSelect.value !== '';

    const hasApprovedSupplierCost =
        approvedSupplierTotal > 0;

    const hasManualSupplierRate =
        !hasApprovedSupplierCost
        && supplierSelected
        && supplierRate > 0;

    const costBase =
        hasApprovedSupplierCost
            ? approvedSupplierTotal
            : (
                hasManualSupplierRate
                    ? supplierRate
                    : packageTotal
            );

    const commissionType =
        document.getElementById('crmCommissionType')?.value || 'fixed';

    const commissionValue =
        Math.max(0, Number(document.getElementById('crmCommissionValue')?.value || 0));

    const commissionAmount =
        commissionType === 'percent'
            ? (costBase * commissionValue / 100)
            : commissionValue;

    const discount =
        Math.max(0, Number(document.getElementById('crmDiscountAmount')?.value || 0));

    const finalPrice =
        Math.max(0, costBase + commissionAmount - discount);

    const hasPackageRate = packageTotal > 0;
    const hasSupplierRate = hasApprovedSupplierCost || hasManualSupplierRate;
    const hasUsableCost =
        approvedSupplierValid
        && (
            hasSupplierRate
            || hasPackageRate
        );

    document.getElementById('crmAdultUnitRate').textContent = adultRate > 0 ? crmMoneyNumber(adultRate) : 'Not configured';
    document.getElementById('crmAdultSubtotal').textContent = adultsTotal > 0 ? crmMoneyNumber(adultsTotal) : '—';
    document.getElementById('crmChildSubtotal').textContent = childrenTotal > 0 ? crmMoneyNumber(childrenTotal) : '—';
    document.getElementById('crmPackageAutoTotal').textContent = hasPackageRate ? crmMoneyNumber(packageTotal) : 'Rate not configured';
    document.getElementById('crmPackageAutoTotalInput').value = packageTotal.toFixed(2);

    const supplierCostDisplay = document.getElementById('crmSupplierCostDisplay');
    if (supplierCostDisplay) {
        supplierCostDisplay.textContent =
            hasApprovedSupplierCost
                ? crmMoneyNumber(approvedSupplierTotal)
                : (
                    hasManualSupplierRate
                        ? crmMoneyNumber(supplierRate)
                        : (
                            supplierSelected
                                ? 'Enter supplier rate'
                                : 'Not selected'
                        )
                );
    }

    document.getElementById('crmCommissionAmount').value = commissionAmount.toFixed(2);

    const crmProfitDisplay = document.getElementById('crmProfitDisplay');
    if (crmProfitDisplay) {
        crmProfitDisplay.textContent = crmMoneyNumber(commissionAmount);
    }

    const crmProfitMessage = document.getElementById('crmProfitMessage');
    if (crmProfitMessage) {
        crmProfitMessage.textContent = 'Your current profit is ' + crmMoneyNumber(commissionAmount) + '.';
    }

    const sourceLabel =
        hasApprovedSupplierCost
            ? (
                approvedSupplierModeLabel
                    ? 'Selected Supplier Quotes — ' + approvedSupplierModeLabel
                    : 'Selected Supplier Quotes'
            )
            : (
                hasManualSupplierRate
                    ? 'Manual Supplier Rate'
                    : (
                        hasPackageRate
                            ? 'Package Master Auto Price'
                            : 'Rate Not Available'
                    )
            );

    document.getElementById('crmCostSourceLabel').value = sourceLabel;
    document.getElementById('crmBasePrice').value = packageTotal.toFixed(2);
    document.getElementById('crmFinalPrice').value = finalPrice.toFixed(2);
    document.getElementById('crmFinalPriceDisplay').textContent = hasUsableCost ? crmMoneyNumber(finalPrice) : 'Waiting for rate';

    const customerBaseDisplay = document.getElementById('crmCustomerBaseDisplay');
    const customerProfitDisplay = document.getElementById('crmCustomerProfitDisplay');
    const customerDiscountDisplay = document.getElementById('crmCustomerDiscountDisplay');
    const customerFinalDisplay = document.getElementById('crmCustomerFinalDisplay');
    const customerSourceDisplay = document.getElementById('crmCustomerSourceDisplay');
    if (customerBaseDisplay) customerBaseDisplay.textContent = crmMoneyNumber(costBase);
    if (customerProfitDisplay) customerProfitDisplay.textContent = crmMoneyNumber(commissionAmount);
    if (customerDiscountDisplay) customerDiscountDisplay.textContent = crmMoneyNumber(discount);
    if (customerFinalDisplay) customerFinalDisplay.textContent = hasUsableCost ? crmMoneyNumber(finalPrice) : 'Waiting for rate';
    if (customerSourceDisplay) customerSourceDisplay.textContent = sourceLabel;

    document.getElementById('crmFinalFormula').textContent =
        !approvedSupplierValid
            ? 'Selected supplier costing needs review before this quotation can be saved.'
            : (
                hasUsableCost
                    ? (
                        sourceLabel
                        + ' + '
                        + crmMoneyNumber(commissionAmount)
                        + ' profit / commission'
                        + (
                            discount > 0
                                ? ' − ' + crmMoneyNumber(discount) + ' discount'
                                : ''
                        )
                    )
                    : 'No usable package/supplier rate yet. Configure Package Master pricing or select supplier costing.'
            );
}

function crmEscapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function crmRenumberItineraryCards() {
    if (!crmItineraryRows) return;

    const cards = Array.from(
        crmItineraryRows.querySelectorAll('.itinerary-edit-card')
    );

    cards.forEach(function(card, index) {
        card.dataset.dayIndex = String(index);

        const label = card.querySelector('.day-label');
        if (label) {
            label.textContent = 'DAY ' + (index + 1);
        }

        const moveSelect = card.querySelector('.itinerary-move-to');
        if (moveSelect) {
            const currentValue = String(index + 1);
            moveSelect.innerHTML = cards.map(function(_, optionIndex) {
                const dayNumber = optionIndex + 1;
                return '<option value="' + dayNumber + '"'
                    + (dayNumber === index + 1 ? ' selected' : '')
                    + '>Move to Day ' + dayNumber + '</option>';
            }).join('');
            moveSelect.value = currentValue;
        }

        const up = card.querySelector('.itinerary-move-up');
        const down = card.querySelector('.itinerary-move-down');

        if (up) up.disabled = index === 0;
        if (down) down.disabled = index === cards.length - 1;
    });
    window.crmItineraryStudioV2497?.refresh();
}

function crmMoveItineraryCard(card, targetIndex) {
    if (!crmItineraryRows || !card) return;

    const cards = Array.from(
        crmItineraryRows.querySelectorAll('.itinerary-edit-card')
    );

    const currentIndex = cards.indexOf(card);

    if (
        currentIndex < 0
        ||
        targetIndex < 0
        ||
        targetIndex >= cards.length
        ||
        currentIndex === targetIndex
    ) {
        crmRenumberItineraryCards();
        return;
    }

    /*
     * Move the existing DOM card itself instead of rebuilding the form.
     * This preserves typed text and even a PC image already selected in
     * the file input.
     */
    const remaining = cards.filter(item => item !== card);

    if (targetIndex >= remaining.length) {
        crmItineraryRows.appendChild(card);
    } else {
        crmItineraryRows.insertBefore(
            card,
            remaining[targetIndex]
        );
    }

    crmRenumberItineraryCards();
    window.crmItineraryStudioV2497?.changed();

    card.classList.add('itinerary-just-moved');
    window.setTimeout(function() {
        card.classList.remove('itinerary-just-moved');
    }, 700);
}

function itineraryCard(day, index) {
    const wrapper = document.createElement('article');
    wrapper.className = 'itinerary-edit-card';
    wrapper.dataset.dayIndex = String(index);

    const image = day.image || '';

    wrapper.innerHTML = `
        <div class="itinerary-card-head">
            <span class="day-label">DAY ${index + 1}</span>

            <div class="itinerary-order-tools">
                <button
                    type="button"
                    class="itinerary-order-button itinerary-move-up"
                    title="Move this complete itinerary one day earlier"
                >
                    <i class="fa-solid fa-arrow-up"></i>
                    Earlier
                </button>

                <button
                    type="button"
                    class="itinerary-order-button itinerary-move-down"
                    title="Move this complete itinerary one day later"
                >
                    <i class="fa-solid fa-arrow-down"></i>
                    Later
                </button>

                <select
                    class="itinerary-move-to"
                    aria-label="Move itinerary to another day"
                ></select>
            </div>
        </div>

        <div class="itinerary-card-grid">
            <div class="field full">
                <label>Day Title</label>
                <input
                    class="control"
                    name="itinerary_title[]"
                    value="${crmEscapeHtml(day.title || '')}"
                    placeholder="Example: Arrival in Port Blair & Cellular Jail"
                >
            </div>

            <div class="field full">
                <label>Day Description</label>
                <textarea
                    class="control"
                    name="itinerary_description[]"
                    placeholder="Describe this day's hotel, sightseeing, transfer, meals and activities..."
                >${crmEscapeHtml(day.description || '')}</textarea>
            </div>

            <div class="field">
                <label>Day Image</label>
                <input
                    type="hidden"
                    name="itinerary_existing_image[]"
                    value="${crmEscapeHtml(image)}"
                >
                <input
                    class="control file-control itinerary-file-input"
                    type="file"
                    name="itinerary_image[]"
                    accept="image/jpeg,image/png,image/webp"
                    hidden
                >

                <div class="media-choice-row">
                    <button type="button" class="media-choice pc itinerary-pc">
                        <i class="fa-solid fa-computer"></i> Computer / PC
                    </button>
                    <button type="button" class="media-choice web itinerary-web">
                        <i class="fa-regular fa-images"></i> Web Gallery
                    </button>
                    <button type="button" class="media-choice free itinerary-free">
                        <i class="fa-solid fa-magnifying-glass"></i> Free Image Search
                    </button>
                </div>

                <div class="itinerary-preview">
                    ${image ? '<img src="' + crmImageUrl(image) + '" alt="Day image">' : ''}
                </div>
            </div>
        </div>
    `;

    const file = wrapper.querySelector('input[type="file"]');
    const preview = wrapper.querySelector('.itinerary-preview');

    file.addEventListener('change', function () {
        if (this.files && this.files[0]) {
            preview.innerHTML =
                '<img src="' + URL.createObjectURL(this.files[0]) + '" alt="New day image">';
        }
    });

    wrapper
        .querySelector('.itinerary-move-up')
        ?.addEventListener('click', function() {
            const current =
                Array.from(
                    crmItineraryRows.querySelectorAll('.itinerary-edit-card')
                ).indexOf(wrapper);

            crmMoveItineraryCard(
                wrapper,
                current - 1
            );
        });

    wrapper
        .querySelector('.itinerary-move-down')
        ?.addEventListener('click', function() {
            const current =
                Array.from(
                    crmItineraryRows.querySelectorAll('.itinerary-edit-card')
                ).indexOf(wrapper);

            crmMoveItineraryCard(
                wrapper,
                current + 1
            );
        });

    wrapper
        .querySelector('.itinerary-move-to')
        ?.addEventListener('change', function() {
            const target =
                Math.max(
                    0,
                    Number(this.value || 1) - 1
                );

            crmMoveItineraryCard(
                wrapper,
                target
            );
        });

    return wrapper;
}

function renderItinerary(days, forcedCount = null) {
    if (!crmItineraryRows) return;

    const count = forcedCount !== null
        ? Math.max(0, Number(forcedCount || 0))
        : Math.max(0, Number(crmDurationDays?.value || 0));

    const source = Array.isArray(days) ? days : [];
    crmItineraryRows.innerHTML = '';

    for (let index = 0; index < count; index++) {
        crmItineraryRows.appendChild(
            itineraryCard(source[index] || {}, index)
        );
    }

    crmRenumberItineraryCards();
}

function fillCustomerPackage(pkg) {
    if (crmPackageTitle) crmPackageTitle.value = pkg.title || '';
    if (crmDestinationId) crmDestinationId.value = pkg.destination_id || '';
    if (crmPackageType) crmPackageType.value = pkg.package_type || 'holiday';
    if (crmPackageCode) crmPackageCode.value = pkg.package_code || '';
    if (crmCurrency) crmCurrency.value = pkg.currency || 'INR';
    if (crmShortDescription) crmShortDescription.value = pkg.short_description || '';
    if (crmPackageDescription) crmPackageDescription.value = pkg.description || '';
    if (crmDurationDays) crmDurationDays.value = Number(pkg.duration_days || 0);
    if (crmDurationNights) crmDurationNights.value = Number(pkg.duration_nights || 0);
    if (crmTourHighlights) crmTourHighlights.value = pkg.tour_highlights || '';
    if (crmPackageInclusions) crmPackageInclusions.value = pkg.inclusions || '';
    if (crmPackageExclusions) crmPackageExclusions.value = pkg.exclusions || '';
    if (crmTerms) crmTerms.value = pkg.terms || '';
    if (crmBasePrice) crmBasePrice.value = Number(pkg.sale_price || 0);
    if (crmDiscountAmount) crmDiscountAmount.value = 0;

    setMainPreview(pkg.main_image || '');
    renderGallery(pkg.gallery_images || []);
    renderMasterPriceRows(pkg.date_prices || []);
    renderMasterChildPrices(pkg.child_prices || []);
    renderItinerary(pkg.itinerary_days || [], Number(pkg.duration_days || 0));

    crmCurrentPackagePricing = pkg;
    crmFilterSuppliersForDestination(pkg.destination_id || '');
    crmRenderChildAgeInputs();
    calculateCrmFinalPrice();
}

crmPackageSelect?.addEventListener('change', function () {
    const option = this.options[this.selectedIndex];
    const raw = option?.dataset?.package || '';

    if (!raw) return;

    try {
        fillCustomerPackage(JSON.parse(raw));
    } catch (error) {
        alert('Unable to load the selected master package.');
    }
});

/*
 * If this enquiry came from a package enquiry, automatically load that same
 * master package into Quotation / Package Studio. This only copies package
 * data into the customer-specific editor; the master package remains protected.
 */
if (
    crmPackageSelect
    &&
    crmPackageSelect.value !== ''
) {
    const enquiryPackageOption =
        crmPackageSelect.options[
            crmPackageSelect.selectedIndex
        ];

    const enquiryPackageRaw =
        enquiryPackageOption?.dataset?.package
        || '';

    if (enquiryPackageRaw) {
        try {
            fillCustomerPackage(
                JSON.parse(enquiryPackageRaw)
            );
        } catch (error) {
            console.error(
                'Unable to auto-load enquiry package.',
                error
            );
        }
    }
}

crmDurationDays?.addEventListener('change', function () {
    // V24.97 changes day count incrementally so selected local files are not lost.
    if (window.crmItineraryStudioV2497?.resize(this.value)) return;
    const existing = [];
    crmItineraryRows?.querySelectorAll('.itinerary-edit-card').forEach(function (card) {
        existing.push({
            title: card.querySelector('[name="itinerary_title[]"]')?.value || '',
            description: card.querySelector('[name="itinerary_description[]"]')?.value || '',
            image: card.querySelector('[name="itinerary_existing_image[]"]')?.value || ''
        });
    });
    renderItinerary(existing, this.value);
});

crmMainImage?.addEventListener('change', function () {
    if (this.files && this.files[0]) {
        crmMainPreview.innerHTML = '<img src="' + URL.createObjectURL(this.files[0]) + '" alt="New main image">';
    }
});

crmGalleryImages?.addEventListener('change', function () {
    const existing = Array.from(
        crmExistingGalleryInputs?.querySelectorAll('input') || []
    ).map(input => input.value);

    renderGallery(existing);

    Array.from(this.files || []).forEach(function (file) {
        const item = document.createElement('div');
        item.className = 'gallery-preview-item';
        item.innerHTML = '<img src="' + URL.createObjectURL(file) + '" alt="' + file.name + '">';
        crmGalleryPreview.appendChild(item);
    });
});

[
    'crmTravelDate',
    'crmPricingHotelCategory',
    'crmPricingAdults',
    'crmPricingChildren',
    'crmCostingSupplier',
    'crmSupplierRate',
    'crmCommissionType',
    'crmCommissionValue',
    'crmDiscountAmount'
].forEach(function (id) {
    const element = document.getElementById(id);

    element?.addEventListener('input', function () {
        if (id === 'crmPricingChildren') {
            crmRenderChildAgeInputs();
        }

        if (id === 'crmCostingSupplier' && this.value === '') {
            const rate = document.getElementById('crmSupplierRate');
            if (rate) rate.value = '0';
        }

        calculateCrmFinalPrice();
    });

    element?.addEventListener('change', function () {
        if (id === 'crmPricingChildren') {
            crmRenderChildAgeInputs();
        }

        calculateCrmFinalPrice();
    });
});

document.getElementById('clearCustomerPackage')?.addEventListener('click', function () {
    if (!confirm('Clear the customer package editor?')) return;
    document.getElementById('customerPackageForm')?.reset();
    setMainPreview('');
    renderGallery([]);
    renderMasterPriceRows([]);
    renderMasterChildPrices([]);
    crmCurrentPackagePricing = null;
    crmRenderChildAgeInputs();
    renderItinerary([], 0);
    calculateCrmFinalPrice();
});

/*
 * Initial empty state must NOT overwrite an automatically loaded enquiry package.
 * Previously renderItinerary([], 0) ran after fillCustomerPackage() and erased
 * all itinerary cards from Quotation Studio.
 */
if (
    !crmPackageSelect
    ||
    crmPackageSelect.value === ''
) {
    renderMasterPriceRows([]);
    renderMasterChildPrices([]);
    renderItinerary([], 0);
}

crmRenderChildAgeInputs();
calculateCrmFinalPrice();

function crmBuildPricingModules() {
    const grid = document.querySelector('.crm-simple-price-summary');
    if (!grid || grid.dataset.modulesBuilt === '1') return;

    const tiles = Array.from(grid.querySelectorAll('.crm-price-tile[data-pricing-open]'));
    tiles.forEach(function (tile) {
        const panelId = tile.getAttribute('data-pricing-open');
        const panel = document.getElementById(panelId);
        if (!panel) return;

        const module = document.createElement('div');
        const tone = ['blue','amber','violet','green'].find(function (name) {
            return tile.classList.contains(name);
        }) || '';
        module.className = 'crm-price-module ' + tone;
        tile.parentNode.insertBefore(module, tile);
        module.appendChild(tile);
        module.appendChild(panel);
    });

    grid.dataset.modulesBuilt = '1';
}

crmBuildPricingModules();

function crmClosePricingPanels() {
    document.querySelectorAll('.crm-price-panel').forEach(function (panel) {
        panel.hidden = true;
    });
    document.querySelectorAll('.crm-price-tile').forEach(function (tile) {
        tile.classList.remove('active');
    });
    document.querySelectorAll('.crm-price-module').forEach(function (module) {
        module.classList.remove('active');
    });
}

function crmOpenPricingPanel(panelId, sourceTile) {
    const panel = document.getElementById(panelId);
    if (!panel) return;
    const module = sourceTile ? sourceTile.closest('.crm-price-module') : null;
    const wasOpen = !panel.hidden;
    crmClosePricingPanels();
    if (wasOpen) return;
    panel.hidden = false;
    if (sourceTile) sourceTile.classList.add('active');
    if (module) module.classList.add('active');
    (module || panel).scrollIntoView({behavior:'smooth', block:'nearest'});
}

document.querySelectorAll('[data-pricing-open]').forEach(function (tile) {
    tile.addEventListener('click', function () {
        crmOpenPricingPanel(this.getAttribute('data-pricing-open'), this);
    });
});

document.querySelectorAll('[data-close-pricing-panel]').forEach(function (button) {
    button.addEventListener('click', crmClosePricingPanels);
});

document.querySelector('[data-jump-save]')?.addEventListener('click', function () {
    crmClosePricingPanels();
    document.getElementById('qdeskSaveBar')?.scrollIntoView({behavior:'smooth', block:'center'});
});

/* --------------------------------------------------------------------------
   PROFESSIONAL CRM MEDIA PICKER: PC + WEB GALLERY + PEXELS FREE SEARCH
----------------------------------------------------------------------------*/
const crmMediaModal = document.getElementById('crmMediaModal');
const crmMediaGrid = document.getElementById('crmMediaGrid');
const crmMediaSearch = document.getElementById('crmMediaSearch');
const crmPexelsSearchButton = document.getElementById('crmPexelsSearchButton');
const crmFreePhotoPagination = document.getElementById('crmFreePhotoPagination');
const crmFreePhotoPrevious = document.getElementById('crmFreePhotoPrevious');
const crmFreePhotoNext = document.getElementById('crmFreePhotoNext');
const crmFreePhotoPageText = document.getElementById('crmFreePhotoPageText');
const crmMediaTitle = document.getElementById('crmMediaTitle');
const crmMediaDone = document.getElementById('crmMediaDone');
const crmMediaHelp = document.getElementById('crmMediaHelp');
const crmMediaLibrary = (() => { try { return JSON.parse(document.getElementById('crmMediaLibraryData')?.textContent || '[]'); } catch(e){ return []; } })();
let crmPickerMode = 'main';
let crmPickerSource = 'web';
let crmActiveItineraryCard = null;

function crmOpenMedia(mode, source, card = null) {
    crmPickerMode = mode;
    crmPickerSource = source;
    crmActiveItineraryCard = card;
    crmMediaModal?.classList.add('open');
    crmMediaModal?.setAttribute('aria-hidden','false');
    if (crmMediaSearch) crmMediaSearch.value = '';
    if (crmPexelsSearchButton) crmPexelsSearchButton.hidden = source !== 'free';
    if (crmFreePhotoPagination) crmFreePhotoPagination.hidden = true;
    if (crmMediaDone) {
        crmMediaDone.hidden = mode !== 'gallery';
        if (mode === 'gallery') {
            const total = crmExistingGalleryInputs?.querySelectorAll('input[name="existing_gallery_images[]"]').length || 0;
            crmMediaDone.innerHTML = '<i class="fa-solid fa-check"></i> Done (' + total + ')';
        }
    }
    if (crmMediaTitle) crmMediaTitle.textContent = source === 'free' ? 'Search Pexels + Unsplash' : 'Web Gallery';
    if (crmMediaHelp) crmMediaHelp.textContent = source === 'free' ? 'Search Pexels + Unsplash together. Results are combined automatically; if one provider has no result or is temporarily unavailable, the other provider still appears.' : 'Choose an existing image already stored on your website.';
    if (source === 'free') {
        crmMediaGrid.innerHTML = '<div class="crm-search-status">Enter a destination or travel keyword above, then click Search Pexels + Unsplash.</div>';
        crmMediaSearch.placeholder = 'Example: Bali beach, Kashmir mountains...';
    } else {
        crmMediaSearch.placeholder = 'Search image name...';
        crmRenderWebGallery(crmMediaLibrary);
    }
}
function crmCloseMedia(){crmMediaModal?.classList.remove('open');crmMediaModal?.setAttribute('aria-hidden','true');}
document.getElementById('crmMediaClose')?.addEventListener('click', crmCloseMedia);
crmMediaDone?.addEventListener('click', crmCloseMedia);
crmMediaModal?.addEventListener('click', e=>{if(e.target===crmMediaModal)crmCloseMedia();});

function crmRenderWebGallery(items){
    if(!crmMediaGrid)return;
    crmMediaGrid.innerHTML = items.length ? items.map(item => `<div class="crm-media-item"><img src="${item.url}" alt=""><div class="crm-media-item-body"><div class="crm-media-item-name" title="${item.name}">${item.name}</div><button type="button" class="crm-media-select" data-crm-image="${item.image}" data-crm-url="${item.url}">Use Image</button></div></div>`).join('') : '<div class="crm-search-status">No website images found.</div>';
}
crmMediaSearch?.addEventListener('input', function(){
    if(crmPickerSource!=='web')return;
    const q=this.value.trim().toLowerCase();
    crmRenderWebGallery(crmMediaLibrary.filter(i=>String(i.name||'').toLowerCase().includes(q)));
});

function crmApplySelectedMedia(path, url){
    if (!path) return;

    if(crmPickerMode==='main'){
        if(crmExistingMainImage) crmExistingMainImage.value=path;
        setMainPreview(path, url || '');
    } else if(crmPickerMode==='gallery'){
        crmAppendGalleryPreview(path, url || '');
    } else if(crmPickerMode==='itinerary' && crmActiveItineraryCard){
        const hidden=crmActiveItineraryCard.querySelector('[name="itinerary_existing_image[]"]');
        if(hidden) hidden.value=path;
        const preview=crmActiveItineraryCard.querySelector('.itinerary-preview');
        if(preview) preview.innerHTML=`<img src="${url || crmImageUrl(path)}" alt="Day image">`;
        window.crmItineraryStudioV2497?.refresh();
    }

    /*
    | Match Admin Packages behavior:
    | Main and itinerary = single selection, close popup.
    | Additional Gallery = keep popup open for multiple selections.
    */
    if (crmPickerMode !== 'gallery') {
        crmCloseMedia();
    }
}

document.addEventListener('click', function(e){
    const pc=e.target.closest('[data-media-pc]');
    if(pc){ const mode=pc.dataset.mediaPc; (mode==='main'?crmMainImage:crmGalleryImages)?.click(); return; }
    const web=e.target.closest('[data-media-web]'); if(web){crmOpenMedia(web.dataset.mediaWeb,'web');return;}
    const free=e.target.closest('[data-media-free]'); if(free){crmOpenMedia(free.dataset.mediaFree,'free');return;}
    const cardPc=e.target.closest('.itinerary-pc'); if(cardPc){cardPc.closest('.itinerary-edit-card')?.querySelector('.itinerary-file-input')?.click();return;}
    const cardWeb=e.target.closest('.itinerary-web'); if(cardWeb){crmOpenMedia('itinerary','web',cardWeb.closest('.itinerary-edit-card'));return;}
    const cardFree=e.target.closest('.itinerary-free'); if(cardFree){crmOpenMedia('itinerary','free',cardFree.closest('.itinerary-edit-card'));return;}
    const choose=e.target.closest('.crm-media-select:not(.crm-pexels-use):not(.crm-free-photo-use)'); if(choose){crmApplySelectedMedia(choose.dataset.crmImage||'', choose.dataset.crmUrl||''); if(crmPickerMode==='gallery'){choose.closest('.crm-media-item')?.classList.add('selected'); choose.textContent='Selected';}}
});

async function crmPostMedia(data){
    const body=new URLSearchParams({...data,csrf_token:<?= json_encode($csrfToken); ?>});
    const response=await fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body});
    const json=await response.json();
    if(!response.ok||!json.success)throw new Error(json.message||'Image request failed.');
    return json;
}
let crmCombinedFreePhotos = [];
let crmFreePhotoProviderFilter = 'all';
let crmFreePhotoCurrentPage = 1;
let crmFreePhotoHasNext = false;
let crmFreePhotoHasPrev = false;
let crmFreePhotoProviderStatus = null;
let crmFreePhotoQuery = '';

function crmEscapeMediaHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function crmRenderCombinedFreePhotos(filter = 'all', providerStatus = null) {
    crmFreePhotoProviderFilter = filter || 'all';
    const photos = crmCombinedFreePhotos.filter(function (photo) {
        return crmFreePhotoProviderFilter === 'all'
            || String(photo.provider || '').toLowerCase() === crmFreePhotoProviderFilter;
    });

    const allCount = crmCombinedFreePhotos.length;
    const pexelsCount = crmCombinedFreePhotos.filter(p => String(p.provider||'').toLowerCase()==='pexels').length;
    const unsplashCount = crmCombinedFreePhotos.filter(p => String(p.provider||'').toLowerCase()==='unsplash').length;

    const providerMessages = providerStatus
        ? Object.values(providerStatus).filter(Boolean).map(s => s.message || '').filter(Boolean)
        : [];
    const warning = providerStatus
        ? Object.values(providerStatus).some(s => s && s.ok === false)
        : false;

    const meta = `
        <div class="crm-free-search-meta">
            <div>
                <strong style="display:block;color:#0f172a;font-size:11px">Combined royalty-free results</strong>
                <div class="crm-free-provider-note ${warning ? 'warn' : ''}">${crmEscapeMediaHtml(providerMessages.join(' · ') || 'Pexels and Unsplash results are mixed for easier comparison.')}</div>
            </div>
            <div class="crm-free-search-counts">
                <button type="button" class="crm-free-filter ${crmFreePhotoProviderFilter==='all'?'active':''}" data-free-filter="all">All ${allCount}</button>
                <button type="button" class="crm-free-filter ${crmFreePhotoProviderFilter==='pexels'?'active':''}" data-free-filter="pexels">Pexels ${pexelsCount}</button>
                <button type="button" class="crm-free-filter ${crmFreePhotoProviderFilter==='unsplash'?'active':''}" data-free-filter="unsplash">Unsplash ${unsplashCount}</button>
            </div>
        </div>`;

    if (!photos.length) {
        crmMediaGrid.innerHTML = meta + '<div class="crm-search-status">No photos found for this provider. Try All results or another keyword.</div>';
        return;
    }

    crmMediaGrid.innerHTML = meta + photos.map(function (p) {
        const provider = String(p.provider || 'pexels').toLowerCase();
        const label = p.provider_label || (provider === 'unsplash' ? 'Unsplash' : 'Pexels');
        const creditName = p.photographer || (provider === 'unsplash' ? 'Unsplash contributor' : 'Pexels creator');
        const creditLink = p.photographer_url
            ? `<a href="${crmEscapeMediaHtml(p.photographer_url)}" target="_blank" rel="noopener noreferrer">${crmEscapeMediaHtml(creditName)}</a>`
            : crmEscapeMediaHtml(creditName);
        const sourceLink = p.photo_url
            ? `<a href="${crmEscapeMediaHtml(p.photo_url)}" target="_blank" rel="noopener noreferrer">${crmEscapeMediaHtml(label)}</a>`
            : crmEscapeMediaHtml(label);
        const buttonText = provider === 'unsplash' ? 'Use This Photo' : 'Import & Use';
        return `<div class="crm-media-item" data-provider="${provider}">
            <img src="${crmEscapeMediaHtml(p.preview||'')}" alt="${crmEscapeMediaHtml(p.alt||'Travel photo')}">
            <div class="crm-media-item-body">
                <div class="crm-media-item-name">${crmEscapeMediaHtml(p.alt||'Travel photo')}</div>
                <div class="crm-free-credit"><span class="crm-provider-badge ${provider}">${crmEscapeMediaHtml(label)}</span><span>Photo by ${creditLink} on ${sourceLink}</span></div>
                <button type="button" class="crm-media-select crm-free-photo-use" data-photo='${encodeURIComponent(JSON.stringify(p))}'>${buttonText}</button>
            </div>
        </div>`;
    }).join('');
}

async function crmSearchCombinedFreePhotos(page = 1){
    const query=crmMediaSearch?.value.trim()||'';
    if(!query){alert('Enter an image search keyword.');return;}
    crmFreePhotoQuery=query;
    crmFreePhotoCurrentPage=Math.max(1,Number(page||1));
    if(crmFreePhotoPagination) crmFreePhotoPagination.hidden=true;
    if(crmPexelsSearchButton){crmPexelsSearchButton.disabled=true;crmPexelsSearchButton.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Searching both libraries...';}
    crmMediaGrid.innerHTML='<div class="crm-search-status"><i class="fa-solid fa-spinner fa-spin"></i> Searching Pexels + Unsplash and combining the best matching results...</div>';
    try{
        const payload=await crmPostMedia({action:'crm_free_photo_search',query:crmFreePhotoQuery,page:crmFreePhotoCurrentPage});
        crmCombinedFreePhotos=Array.isArray(payload.data?.photos)?payload.data.photos:[];
        crmFreePhotoCurrentPage=Number(payload.data?.page||crmFreePhotoCurrentPage);
        crmFreePhotoHasNext=Boolean(payload.data?.next_page);
        crmFreePhotoHasPrev=Boolean(payload.data?.prev_page)||crmFreePhotoCurrentPage>1;
        crmFreePhotoProviderStatus=payload.data?.provider_status||null;
        crmRenderCombinedFreePhotos('all',crmFreePhotoProviderStatus);
        if(crmFreePhotoPrevious) crmFreePhotoPrevious.disabled=!crmFreePhotoHasPrev;
        if(crmFreePhotoNext) crmFreePhotoNext.disabled=!crmFreePhotoHasNext;
        if(crmFreePhotoPageText) crmFreePhotoPageText.textContent='Page '+crmFreePhotoCurrentPage+' • '+Number(payload.data?.total_results||crmCombinedFreePhotos.length)+' result(s)';
        if(crmFreePhotoPagination) crmFreePhotoPagination.hidden=false;
    }catch(err){
        crmCombinedFreePhotos=[];
        crmMediaGrid.innerHTML=`<div class="crm-search-status">${crmEscapeMediaHtml(err.message)}</div>`;
    }finally{
        if(crmPexelsSearchButton){crmPexelsSearchButton.disabled=false;crmPexelsSearchButton.innerHTML='<i class="fa-solid fa-magnifying-glass"></i> Search Pexels + Unsplash';}
    }
}

crmPexelsSearchButton?.addEventListener('click',function(){crmSearchCombinedFreePhotos(1);});
crmMediaSearch?.addEventListener('keydown',function(e){if(crmPickerSource==='free'&&e.key==='Enter'){e.preventDefault();crmSearchCombinedFreePhotos(1);}});
crmFreePhotoPrevious?.addEventListener('click',function(){if(crmFreePhotoHasPrev)crmSearchCombinedFreePhotos(crmFreePhotoCurrentPage-1);});
crmFreePhotoNext?.addEventListener('click',function(){if(crmFreePhotoHasNext)crmSearchCombinedFreePhotos(crmFreePhotoCurrentPage+1);});

document.addEventListener('click', function(e){
    const filter=e.target.closest('[data-free-filter]');
    if(!filter)return;
    e.preventDefault();
    crmRenderCombinedFreePhotos(filter.getAttribute('data-free-filter') || 'all');
});

document.addEventListener('click', async function(e){
    const btn=e.target.closest('.crm-free-photo-use');
    if(!btn)return;
    e.preventDefault();
    e.stopPropagation();

    let p={};
    try{p=JSON.parse(decodeURIComponent(btn.dataset.photo||''));}catch(err){return;}
    const provider=String(p.provider||'pexels').toLowerCase();
    const old=btn.textContent;
    btn.disabled=true;
    btn.textContent=provider==='unsplash'?'Selecting...':'Importing...';

    try{
        let payload;
        if(provider==='unsplash'){
            payload=await crmPostMedia({
                action:'crm_unsplash_use',
                photo_id:p.id,
                image_url:p.download,
                download_location:p.download_location||'',
                photographer:p.photographer||'',
                photographer_url:p.photographer_url||'',
                photo_url:p.photo_url||''
            });
        }else{
            payload=await crmPostMedia({
                action:'crm_pexels_import',
                photo_id:p.id,
                image_url:p.download,
                alt:p.alt||''
            });
        }

        const d=payload.data||{};
        const savedPath=d.image||'';
        crmApplySelectedMedia(
            savedPath,
            p.preview || d.url || (savedPath ? crmImageUrl(savedPath) : '')
        );

        if(crmPickerMode==='gallery'){
            btn.disabled=true;
            btn.textContent='Selected';
            btn.closest('.crm-media-item')?.classList.add('selected');
        }else{
            btn.disabled=false;
            btn.textContent=old;
        }
    }catch(err){
        alert(err.message);
        btn.disabled=false;
        btn.textContent=old;
    }
});



/* --------------------------------------------------------------------------
   CRM QUOTATION VERSION CONVERSATION
   -------------------------------------------------------------------------- */

const crmReplyModal =
    document.getElementById('crmReplyModal');

const crmReplyClose =
    document.getElementById('crmReplyClose');

const crmReplyLeadId =
    document.getElementById('crmReplyLeadId');

const crmReplyVersionId =
    document.getElementById('crmReplyVersionId');

const crmReplyVersionLabel =
    document.getElementById('crmReplyVersionLabel');

const crmReplyCustomerName =
    document.getElementById('crmReplyCustomerName');

const crmReplyCustomerEmail =
    document.getElementById('crmReplyCustomerEmail');

const crmReplyMessage =
    document.getElementById('crmReplyMessage');

const crmReplyWhatsApp =
    document.getElementById('crmReplyWhatsApp');

const crmConversationThread =
    document.getElementById('crmConversationThread');

const crmConversationStatus =
    document.getElementById('crmConversationStatus');

const crmConversationRefresh =
    document.getElementById('crmConversationRefresh');

let crmConversationState = {
    leadId: '',
    versionId: '',
    versionNumber: '',
    packageTitle: '',
    customerName: '',
    customerEmail: '',
    customerMobile: '',
};


function crmConversationEscape(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


function crmConversationBody(value) {
    return crmConversationEscape(value)
        .replace(/\n/g, '<br>');
}


function crmConversationDate(value) {
    if (!value) {
        return '';
    }

    const date =
        new Date(
            String(value).replace(' ', 'T')
        );

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString(
        [],
        {
            dateStyle: 'medium',
            timeStyle: 'short',
        }
    );
}


function crmCloseReplyModal() {
    if (!crmReplyModal) {
        return;
    }

    crmReplyModal.classList.remove('open');
    crmReplyModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('crm-conversation-open');
}


function crmRenderConversation(messages) {

    if (!crmConversationThread) {
        return;
    }

    if (!Array.isArray(messages) || messages.length === 0) {

        crmConversationThread.innerHTML =
            '<div class="crm-conversation-empty">'
            + '<i class="fa-regular fa-comments"></i>'
            + '<strong>No conversation yet</strong>'
            + '<span>Send this quotation by Email. When the customer replies to that email, the reply will appear here.</span>'
            + '</div>';

        return;
    }

    crmConversationThread.innerHTML =
        messages.map(
            function (message) {

                const incoming =
                    message.direction === 'inbound';

                return (
                    '<div class="crm-message-row '
                    + (incoming ? 'incoming' : 'outgoing')
                    + '">'
                    + '<div class="crm-message-bubble">'
                    + '<div class="crm-message-top">'
                    + '<strong>'
                    + (
                        incoming
                            ? crmConversationEscape(
                                crmConversationState.customerName
                                || message.sender_email
                                || 'Customer'
                            )
                            : 'TRAVSCOPE Admin'
                    )
                    + '</strong>'
                    + '<span>'
                    + crmConversationEscape(
                        crmConversationDate(message.sent_at)
                    )
                    + '</span>'
                    + '</div>'
                    + (
                        message.subject
                            ? '<div class="crm-message-subject">'
                                + crmConversationEscape(message.subject)
                                + '</div>'
                            : ''
                    )
                    + '<div class="crm-message-body">'
                    + crmConversationBody(message.body_text || '')
                    + '</div>'
                    + '</div>'
                    + '</div>'
                );
            }
        ).join('');

    crmConversationThread.scrollTop =
        crmConversationThread.scrollHeight;
}


async function crmLoadConversation() {

    if (
        !crmConversationState.leadId
        || !crmConversationState.versionId
    ) {
        return;
    }

    if (crmConversationThread) {
        crmConversationThread.innerHTML =
            '<div class="crm-conversation-loading">'
            + '<i class="fa-solid fa-circle-notch fa-spin"></i>'
            + ' Syncing replies from email...'
            + '</div>';
    }

    if (crmConversationStatus) {
        crmConversationStatus.textContent = '';
    }

    try {

        const url =
            <?= json_encode(BASE_URL . 'admin-crm.php', JSON_UNESCAPED_SLASHES); ?>
            + '?quotation_conversation=1'
            + '&lead='
            + encodeURIComponent(crmConversationState.leadId)
            + '&version='
            + encodeURIComponent(crmConversationState.versionId);

        const response =
            await fetch(
                url,
                {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                    },
                }
            );

        const payload =
            await response.json();

        if (!response.ok || !payload.ok) {
            throw new Error(
                payload.message
                || 'Unable to load quotation conversation.'
            );
        }

        if (payload.version) {
            crmConversationState.versionNumber =
                payload.version.version_number
                || crmConversationState.versionNumber;

            crmConversationState.packageTitle =
                payload.version.package_title
                || crmConversationState.packageTitle;

            crmConversationState.customerName =
                payload.version.customer_name
                || crmConversationState.customerName;

            crmConversationState.customerEmail =
                payload.version.customer_email
                || crmConversationState.customerEmail;

            crmConversationState.customerMobile =
                payload.version.customer_mobile
                || crmConversationState.customerMobile;
        }

        if (crmReplyVersionLabel) {
            crmReplyVersionLabel.textContent =
                'Version '
                + crmConversationState.versionNumber
                + ' • '
                + crmConversationState.packageTitle;
        }

        if (crmReplyCustomerName) {
            crmReplyCustomerName.textContent =
                crmConversationState.customerName
                || 'Customer';
        }

        if (crmReplyCustomerEmail) {
            crmReplyCustomerEmail.textContent =
                crmConversationState.customerEmail
                || 'No customer email';
        }

        if (
            crmConversationStatus
            && payload.sync
            && payload.sync.message
        ) {
            crmConversationStatus.textContent =
                payload.sync.message;
        }

        crmRenderConversation(
            payload.messages || []
        );

    } catch (error) {

        if (crmConversationThread) {
            crmConversationThread.innerHTML =
                '<div class="crm-conversation-error">'
                + '<i class="fa-solid fa-triangle-exclamation"></i>'
                + crmConversationEscape(
                    error.message
                    || 'Unable to load conversation.'
                )
                + '</div>';
        }
    }
}


function crmOpenReplyModal(button) {

    crmConversationState = {
        leadId: button.dataset.leadId || '',
        versionId: button.dataset.versionId || '',
        versionNumber: button.dataset.versionNumber || '',
        packageTitle: button.dataset.packageTitle || '',
        customerName: button.dataset.customerName || 'Customer',
        customerEmail: button.dataset.customerEmail || '',
        customerMobile: button.dataset.customerMobile || '',
    };

    if (crmReplyLeadId) {
        crmReplyLeadId.value =
            crmConversationState.leadId;
    }

    if (crmReplyVersionId) {
        crmReplyVersionId.value =
            crmConversationState.versionId;
    }

    if (crmReplyVersionLabel) {
        crmReplyVersionLabel.textContent =
            'Version '
            + crmConversationState.versionNumber
            + ' • '
            + crmConversationState.packageTitle;
    }

    if (crmReplyCustomerName) {
        crmReplyCustomerName.textContent =
            crmConversationState.customerName;
    }

    if (crmReplyCustomerEmail) {
        crmReplyCustomerEmail.textContent =
            crmConversationState.customerEmail;
    }

    if (crmReplyMessage) {
        crmReplyMessage.value = '';
    }

    const digits =
        String(
            crmConversationState.customerMobile
            || ''
        ).replace(/\D+/g, '');

    let number = digits;

    if (digits.length === 10) {
        number = '91' + digits;
    }

    if (crmReplyWhatsApp && number) {
        crmReplyWhatsApp.href =
            'https://wa.me/'
            + encodeURIComponent(number);

        crmReplyWhatsApp.style.display = '';
    } else if (crmReplyWhatsApp) {
        crmReplyWhatsApp.style.display = 'none';
    }

    if (!crmReplyModal) {
        console.error('Quotation conversation modal was not found.');
        return;
    }

    crmReplyModal.classList.add('open');
    crmReplyModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('crm-conversation-open');

    crmLoadConversation();
}


document.querySelectorAll('[data-reply-open]').forEach(function (button) {
    button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        crmOpenReplyModal(button);
    });
});


crmConversationRefresh?.addEventListener(
    'click',
    crmLoadConversation
);


crmReplyClose?.addEventListener(
    'click',
    crmCloseReplyModal
);


crmReplyModal?.addEventListener(
    'click',
    function (event) {

        if (event.target === crmReplyModal) {
            crmCloseReplyModal();
        }
    }
);


const leadModal = document.getElementById('leadModal');
document.getElementById('openLeadModal')?.addEventListener('click', function () {
    leadModal?.classList.add('open');
    leadModal?.setAttribute('aria-hidden', 'false');
});
document.getElementById('closeLeadModal')?.addEventListener('click', function () {
    leadModal?.classList.remove('open');
    leadModal?.setAttribute('aria-hidden', 'true');
});
leadModal?.addEventListener('click', function (event) {
    if (event.target === leadModal) {
        leadModal.classList.remove('open');
    }
});
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        leadModal?.classList.remove('open');
    }
});
</script>

<script>
(function () {
    const tabs = document.getElementById('crmWorkspaceTabs');
    if (!tabs) return;

    const buttons = Array.from(tabs.querySelectorAll('[data-crm-tab]'));
    const panels = Array.from(document.querySelectorAll('.crm-workspace-panel[data-crm-panel]'));

    function openCrmPanel(name, updateHash) {
        const target = panels.find(function (panel) {
            return panel.getAttribute('data-crm-panel') === name;
        });

        if (!target) return;

        panels.forEach(function (panel) {
            panel.classList.remove('is-active');
        });

        buttons.forEach(function (button) {
            button.classList.toggle(
                'active',
                button.getAttribute('data-crm-tab') === name
            );
        });

        target.classList.add('is-active');

        if (updateHash) {
            history.replaceState(null, '', '#crm-' + name);
        }

        if (window.innerWidth > 850) {
            const page = document.querySelector('.page');
            if (page) {
                const targetTop = Math.max(0, tabs.offsetTop - 6);
                page.scrollTo({top:targetTop, behavior:'smooth'});
            }
        }
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            openCrmPanel(this.getAttribute('data-crm-tab'), true);
        });
    });

    /*
     * CRM shortcut actions (for example the Quotation button inside Lead Workspace)
     * must open the same hidden workspace panel as the top tabs.
     */
    document.querySelectorAll('[data-crm-open]').forEach(function (shortcut) {
        shortcut.addEventListener('click', function (event) {
            const panelName = this.getAttribute('data-crm-open');

            if (!panelName) return;

            event.preventDefault();
            openCrmPanel(panelName, true);
        });
    });

    const requested = window.location.hash.replace('#crm-', '');
    const valid = buttons.some(function (button) {
        return button.getAttribute('data-crm-tab') === requested;
    });

    openCrmPanel(valid ? requested : 'overview', false);

    window.addEventListener('hashchange', function () {
        const hashPanel = window.location.hash.replace('#crm-', '');
        const hashValid = buttons.some(function (button) {
            return button.getAttribute('data-crm-tab') === hashPanel;
        });

        if (hashValid) {
            openCrmPanel(hashPanel, false);
        }
    });
})();
</script>



<script>
(function(){
    const sidebar=document.getElementById('sidebar');
    if(!sidebar) return;

    const toggle=document.getElementById('tsMobileSidebarToggle');
    const overlay=document.getElementById('tsSidebarOverlay');
    const storageKey='travscope_admin_sidebar_scroll';

    /*
    |--------------------------------------------------------------------------
    | KEEP LEFT PANEL POSITION CONSTANT BETWEEN PHP PAGES
    |--------------------------------------------------------------------------
    */
    try{
        const saved=parseInt(sessionStorage.getItem(storageKey)||'0',10);
        if(Number.isFinite(saved) && saved>=0){
            sidebar.scrollTop=saved;
            const menu=sidebar.querySelector('.sidebar-menu');
            if(menu) menu.scrollTop=saved;
        }
    }catch(e){}

    const menu=sidebar.querySelector('.sidebar-menu');

    const saveScroll=()=>{
        try{
            const value=menu ? menu.scrollTop : sidebar.scrollTop;
            sessionStorage.setItem(storageKey,String(value||0));
        }catch(e){}
    };

    if(menu){
        menu.addEventListener('scroll',saveScroll,{passive:true});
    }else{
        sidebar.addEventListener('scroll',saveScroll,{passive:true});
    }

    sidebar.querySelectorAll('a.menu-link, a.sidebar-logo').forEach(link=>{
        link.addEventListener('click',saveScroll);
    });

    /*
    |--------------------------------------------------------------------------
    | UNIVERSAL MOBILE HAMBURGER
    |--------------------------------------------------------------------------
    */
    const setOpen=(open)=>{
        sidebar.classList.toggle('ts-sidebar-open',open);
        sidebar.classList.toggle('open',open);

        if(toggle){
            toggle.classList.toggle('open',open);
            toggle.setAttribute('aria-expanded',open?'true':'false');
            toggle.setAttribute('aria-label',open?'Close admin menu':'Open admin menu');
        }

        if(overlay){
            overlay.classList.toggle('ts-sidebar-open',open);
            overlay.setAttribute('aria-hidden',open?'false':'true');
        }

        document.body.classList.toggle('ts-sidebar-lock',open);
    };

    if(toggle){
        toggle.addEventListener('click',function(){
            setOpen(!sidebar.classList.contains('ts-sidebar-open'));
        });
    }

    if(overlay){
        overlay.addEventListener('click',()=>setOpen(false));
    }

    document.addEventListener('keydown',function(event){
        if(event.key==='Escape'){
            setOpen(false);
        }
    });

    /*
    | On mobile, close drawer after a menu selection.
    | The saved scroll position remains for the next page.
    */
    sidebar.querySelectorAll('.menu-link').forEach(link=>{
        link.addEventListener('click',function(){
            saveScroll();
            if(window.matchMedia('(max-width:850px)').matches){
                setOpen(false);
            }
        });
    });

    /*
    | Desktop always remains open. Resizing from mobile to desktop cannot
    | leave the sidebar hidden or the overlay visible.
    */
    window.addEventListener('resize',function(){
        if(window.innerWidth>850){
            setOpen(false);
            sidebar.classList.remove('ts-sidebar-open','open');
        }
    });
})();
</script>



<script>
(function(){
    const root=document.getElementById('qdeskShell');
    if(!root) return;
    const currency=root.dataset.currency||'INR';
    const supplierCost=parseFloat(root.dataset.supplierCost||'0')||0;
    const money=(n)=>currency+' '+(Number(n||0)).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
    const val=(id)=>document.getElementById(id)?.value||'';
    const num=(id)=>parseFloat(val(id)||'0')||0;
    const text=(id,v)=>{const e=document.getElementById(id);if(e)e.textContent=v;};

    function update(){
        const title=val('crmPackageTitle').trim() || document.getElementById('crmPackageSelect')?.selectedOptions?.[0]?.textContent?.trim() || 'Select a package';
        const supplierRateLink=document.getElementById('qdeskSupplierPackageRate');
        const currentPackageSelect=document.getElementById('crmPackageSelect');
        if(supplierRateLink){
            const tripId=supplierRateLink.dataset.tripId||'';
            const packageId=currentPackageSelect?.value||'';
            supplierRateLink.href='admin-trip-contract-rates.php?trip_id='+encodeURIComponent(tripId)+(packageId!==''?'&package_id='+encodeURIComponent(packageId):'');
            supplierRateLink.title=packageId!==''?'Show supplier rates only for the selected Package Master':'Select a Package Master first to lock supplier rates to the correct package';
        }
        text('qdeskPackageTitle',title);
        const ds=document.getElementById('crmDestinationId');
        text('qdeskDestination',ds && ds.value ? (ds.selectedOptions[0]?.textContent||'-').trim() : '-');
        const d=Math.max(0,parseInt(val('crmDurationDays')||'0',10)||0);
        const n=Math.max(0,parseInt(val('crmDurationNights')||'0',10)||0);
        text('qdeskDuration',(n||d)?(n+'N / '+d+'D'):'-');
        const dayCount=document.querySelectorAll('#crmItineraryRows .itinerary-edit-card').length;
        text('qdeskDays',dayCount ? dayCount+' day'+(dayCount===1?'':'s') : '-');
        const packageCost=num('crmPackageAutoTotalInput');
        const markup=num('crmCommissionAmount');
        const discount=num('crmDiscountAmount');
        const final=num('crmFinalPrice');
        text('qdeskPackageCost',packageCost>0?money(packageCost):'-');
        text('qdeskMarkup',markup>0?money(markup):(markup===0?'0.00':'-'));
        text('qdeskFinalPrice',final>0?money(final):'Not calculated');
        const costBase=supplierCost>0?supplierCost:packageCost;
        text('qdeskMargin',final>0&&costBase>0?money(final-costBase-discount):'-');
        const steps=root.querySelectorAll('.qdesk-step');
        if(steps[2])steps[2].classList.toggle('is-done',title!==''&&title!=='Start a Custom Package'&&title!=='Select a package');
        if(steps[3])steps[3].classList.toggle('is-done',final>0);
    }

    root.querySelectorAll('[data-qdesk-scroll]').forEach(btn=>{
        btn.addEventListener('click',()=>{
            const id=btn.getAttribute('data-qdesk-scroll');
            const target=document.getElementById(id);
            if(!target)return;
            const details=target.closest('details');
            if(details)details.open=true;
            target.scrollIntoView({behavior:'smooth',block:'center'});
            if(target.matches('input,select,textarea'))setTimeout(()=>target.focus({preventScroll:true}),450);
        });
    });

    ['crmPackageSelect','crmPackageTitle','crmDestinationId','crmDurationDays','crmDurationNights','crmPricingHotelCategory','crmPricingAdults','crmPricingChildren','crmCommissionType','crmCommissionValue','crmCommissionAmount','crmDiscountAmount','crmFinalPrice','crmPackageAutoTotalInput'].forEach(id=>{
        const el=document.getElementById(id);
        if(el){el.addEventListener('input',update);el.addEventListener('change',()=>setTimeout(update,30));}
    });
    setInterval(update,800);
    setTimeout(update,100);
})();
</script>

<script src="assets/travscope-pro-admin.js?v=20260916-v2" defer></script><script src="site-brand-sync.js?v=20261005-v2473" defer></script>
<script src="assets/crm-itinerary-studio-v2497.js?v=2497" defer></script>
<script src="assets/crm-laptop-workspaces-v2498.js?v=2498" defer></script>
</body>
</html>
