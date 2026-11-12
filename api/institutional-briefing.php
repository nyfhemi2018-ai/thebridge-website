<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function field(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function safe_text(string $value): string
{
    $singleLine = preg_replace('/[\r\n]+/', ' ', $value);
    return trim($singleLine ?? '');
}

function email_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function make_reference(string $kind): string
{
    try {
        $suffix = strtoupper(bin2hex(random_bytes(3)));
    } catch (Throwable $error) {
        $suffix = strtoupper(substr(hash('sha256', uniqid('', true)), 0, 6));
    }

    return 'FIC-' . $kind . '-' . gmdate('Ymd') . '-' . $suffix;
}

function send_html_mail(
    string $to,
    string $subject,
    string $html,
    string $replyTo,
    bool $automated = false
): bool {
    if (getenv('FIC_MAIL_DRY_RUN') === '1') {
        return true;
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: FIC Technologies <info@fictechs.com>',
        'Reply-To: ' . $replyTo,
        'X-Mailer: FIC Website',
    ];

    if ($automated) {
        $headers[] = 'Auto-Submitted: auto-generated';
        $headers[] = 'X-Auto-Response-Suppress: All';
    }

    return @mail($to, $subject, $html, implode("\r\n", $headers));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'ok' => false,
        'message' => 'This endpoint accepts website requests by POST only.',
    ]);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $allowedHosts = [
        'fictechs.com',
        'www.fictechs.com',
        'fictechs.com.ng',
        'www.fictechs.com.ng',
        'localhost',
        '127.0.0.1',
    ];

    if (!in_array($originHost, $allowedHosts, true)) {
        respond(403, [
            'ok' => false,
            'message' => 'This request could not be verified.',
        ]);
    }
}

$contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($contentType, 'application/json') !== false) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : [];
} else {
    $input = $_POST;
}

$requestType = field($input, 'request_type') === 'demo' ? 'demo' : 'briefing';
$isDemo = $requestType === 'demo';
$reference = make_reference($isDemo ? 'DEMO' : 'BRF');

// Hidden field: genuine visitors never populate it. Return a neutral success to bots.
if (field($input, 'website') !== '') {
    respond(200, [
        'ok' => true,
        'reference' => $reference,
        'acknowledgement_sent' => true,
    ]);
}

$fullName = safe_text(field($input, 'full_name'));
$workEmail = strtolower(field($input, 'work_email'));
$organisation = safe_text(field($input, 'organisation'));
$role = safe_text(field($input, 'role'));
$organisationType = safe_text(field($input, 'organisation_type'));
$demoArea = safe_text(field($input, 'demo_area'));
$briefingFocus = field($input, 'briefing_focus');
$consent = field($input, 'consent');

$allowedOrganisationTypes = [
    'Regulator or public authority',
    'Licensed financial institution',
    'Investor or capital allocator',
    'Broker or trading platform',
    'Policy or research institution',
    'Technology or infrastructure partner',
    'Other institutional stakeholder',
];

$allowedDemoAreas = [
    'FIC Core: governance and TLN',
    'Capital Hub: capital participation',
    'Institutional and regulatory view',
    'Risk intelligence and evidence',
    'Full FIC infrastructure walkthrough',
];

$valid =
    text_length($fullName) >= 2 && text_length($fullName) <= 100 &&
    filter_var($workEmail, FILTER_VALIDATE_EMAIL) !== false &&
    text_length($workEmail) <= 160 &&
    strpos($workEmail, "\r") === false && strpos($workEmail, "\n") === false &&
    text_length($organisation) >= 2 && text_length($organisation) <= 140 &&
    text_length($role) >= 2 && text_length($role) <= 120 &&
    in_array($organisationType, $allowedOrganisationTypes, true) &&
    (!$isDemo || in_array($demoArea, $allowedDemoAreas, true)) &&
    text_length($briefingFocus) >= 10 && text_length($briefingFocus) <= 1500 &&
    $consent === 'yes';

if (!$valid) {
    respond(422, [
        'ok' => false,
        'message' => 'Please complete every required field with valid information.',
    ]);
}

// A short per-IP pause limits automated bursts without storing personal form data.
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fic-request-rate';
$rateFile = $rateDirectory . DIRECTORY_SEPARATOR . hash('sha256', $remoteAddress);

if ((is_dir($rateDirectory) || @mkdir($rateDirectory, 0700, true)) && is_file($rateFile)) {
    $lastRequest = (int) @file_get_contents($rateFile);
    if ($lastRequest > 0 && (time() - $lastRequest) < 20) {
        respond(429, [
            'ok' => false,
            'message' => 'Please wait a moment before sending another request.',
        ]);
    }
}

if (is_dir($rateDirectory)) {
    @file_put_contents($rateFile, (string) time(), LOCK_EX);
}

$safeName = email_html($fullName);
$safeEmail = email_html($workEmail);
$safeOrganisation = email_html($organisation);
$safeRole = email_html($role);
$safeType = email_html($organisationType);
$safeDemoArea = email_html($demoArea);
$safeFocus = nl2br(email_html($briefingFocus));
$safeReference = email_html($reference);
$submittedAt = gmdate('j M Y, H:i') . ' UTC';

$notificationTitle = $isDemo
    ? 'New private demonstration request'
    : 'New institutional briefing request';
$notificationSubject = ($isDemo
    ? 'Private demonstration request - '
    : 'Institutional briefing request - ') . $reference;
$focusLabel = $isDemo ? 'Demonstration objectives' : 'Briefing focus';
$requestSpecificRow = $isDemo
    ? '<tr><td style="padding:9px 0;border-top:1px solid #e1e5e2;color:#6a756d;">Demonstration focus</td><td style="padding:9px 0;border-top:1px solid #e1e5e2;">' . $safeDemoArea . '</td></tr>'
    : '';
$notificationHtml = <<<HTML
<!doctype html>
<html lang="en">
<body style="margin:0;background:#eef0ea;color:#121814;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef0ea;padding:28px 12px;">
    <tr><td align="center">
      <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #d9dfda;">
        <tr><td style="padding:28px 32px;background:#07100a;color:#eef0ea;border-top:3px solid #67d99b;">
          <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#67d99b;">FIC Technologies</div>
          <h1 style="margin:12px 0 0;font-family:Georgia,serif;font-size:30px;font-weight:normal;">{$notificationTitle}</h1>
        </td></tr>
        <tr><td style="padding:30px 32px;">
          <p style="margin:0 0 24px;font-size:13px;color:#4c5b51;">Reference <strong style="color:#17603b;">{$safeReference}</strong> · {$submittedAt}</p>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;line-height:1.55;">
            <tr><td style="width:155px;padding:9px 0;border-top:1px solid #e1e5e2;color:#6a756d;">Name</td><td style="padding:9px 0;border-top:1px solid #e1e5e2;"><strong>{$safeName}</strong></td></tr>
            <tr><td style="padding:9px 0;border-top:1px solid #e1e5e2;color:#6a756d;">Work email</td><td style="padding:9px 0;border-top:1px solid #e1e5e2;"><a href="mailto:{$safeEmail}" style="color:#17603b;">{$safeEmail}</a></td></tr>
            <tr><td style="padding:9px 0;border-top:1px solid #e1e5e2;color:#6a756d;">Organisation</td><td style="padding:9px 0;border-top:1px solid #e1e5e2;">{$safeOrganisation}</td></tr>
            <tr><td style="padding:9px 0;border-top:1px solid #e1e5e2;color:#6a756d;">Role</td><td style="padding:9px 0;border-top:1px solid #e1e5e2;">{$safeRole}</td></tr>
            <tr><td style="padding:9px 0;border-top:1px solid #e1e5e2;color:#6a756d;">Organisation type</td><td style="padding:9px 0;border-top:1px solid #e1e5e2;">{$safeType}</td></tr>
            {$requestSpecificRow}
          </table>
          <div style="margin-top:24px;padding:20px;background:#f3f6f3;border-left:3px solid #2b8b59;">
            <div style="margin-bottom:9px;font-size:10px;letter-spacing:1.6px;text-transform:uppercase;color:#46725a;">{$focusLabel}</div>
            <div style="font-size:14px;line-height:1.7;">{$safeFocus}</div>
          </div>
          <p style="margin:24px 0 0;font-size:12px;color:#6a756d;">Consent to respond was recorded with this submission. Reply to this email to contact the requester directly.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

$acknowledgementSubject = ($isDemo
    ? 'FIC Technologies received your demonstration request - '
    : 'FIC Technologies received your briefing request - ') . $reference;
$acknowledgementTitle = $isDemo
    ? 'Your demonstration request has been received.'
    : 'Your briefing request has been received.';
$acknowledgementIntro = $isDemo
    ? 'Thank you for requesting a private demonstration with FIC Technologies. We have received your request and our team will review the context you shared.'
    : 'Thank you for requesting an institutional briefing with FIC Technologies. Your request is now with our team for review.';
$acknowledgementNext = $isDemo
    ? 'We will get back to you shortly using the details you provided. Please keep the reference above for any follow-up.'
    : 'A member of FIC will respond using the details you provided. Please keep the reference above for any follow-up.';
$acknowledgementHtml = <<<HTML
<!doctype html>
<html lang="en">
<body style="margin:0;background:#eef0ea;color:#121814;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef0ea;padding:28px 12px;">
    <tr><td align="center">
      <table role="presentation" width="640" cellspacing="0" cellpadding="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #d9dfda;">
        <tr><td style="padding:32px;background:#07100a;color:#eef0ea;border-top:3px solid #67d99b;">
          <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#67d99b;">FIC Technologies</div>
          <h1 style="margin:14px 0 0;font-family:Georgia,serif;font-size:32px;font-weight:normal;line-height:1.2;">{$acknowledgementTitle}</h1>
        </td></tr>
        <tr><td style="padding:32px;font-size:14px;line-height:1.75;">
          <p style="margin:0 0 18px;">Dear {$safeName},</p>
          <p style="margin:0 0 18px;">{$acknowledgementIntro}</p>
          <div style="margin:24px 0;padding:18px 20px;background:#f3f6f3;border-left:3px solid #2b8b59;">
            <div style="font-size:10px;letter-spacing:1.6px;text-transform:uppercase;color:#46725a;">Request reference</div>
            <strong style="display:block;margin-top:7px;color:#17603b;letter-spacing:1px;">{$safeReference}</strong>
          </div>
          <p style="margin:0 0 18px;">{$acknowledgementNext}</p>
          <p style="margin:26px 0 0;">Regards,<br><strong>Institutional Engagement</strong><br>FIC Technologies</p>
        </td></tr>
        <tr><td style="padding:20px 32px;background:#f6f8f6;border-top:1px solid #e1e5e2;font-size:11px;line-height:1.6;color:#6a756d;">
          This is an automatic acknowledgement. FIC Technologies does not execute trades, hold client funds, operate as a broker or guarantee investment returns.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

$notificationSent = send_html_mail(
    'info@fictechs.com',
    $notificationSubject,
    $notificationHtml,
    $workEmail
);

if (!$notificationSent) {
    error_log('FIC ' . ($isDemo ? 'demo' : 'briefing') . ' notification failed: ' . $reference);
    respond(502, [
        'ok' => false,
        'message' => 'We could not deliver your request right now. Please try again shortly.',
    ]);
}

$acknowledgementSent = send_html_mail(
    $workEmail,
    $acknowledgementSubject,
    $acknowledgementHtml,
    'info@fictechs.com',
    true
);

if (!$acknowledgementSent) {
    error_log('FIC ' . ($isDemo ? 'demo' : 'briefing') . ' acknowledgement failed: ' . $reference);
}

respond(200, [
    'ok' => true,
    'reference' => $reference,
    'acknowledgement_sent' => $acknowledgementSent,
]);
