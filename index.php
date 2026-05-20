<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/ReceiptUrlBuilder.php';
require_once __DIR__ . '/lib/TelebirrReceipt.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function resolveTransactionId(): ?string
{
    if (!empty($_GET['id'])) {
        return trim((string) $_GET['id']);
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $segment = trim(str_replace($base, '', $path), '/');

    return $segment !== '' && $segment !== 'index.php' ? $segment : null;
}

function jsonResponse(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$transactionId = resolveTransactionId();
if ($transactionId === null || $transactionId === '') {
    jsonResponse(400, [
        'error' => 'Missing transaction id',
        'usage' => [
            'http://localhost/telebirr_payment/ABC12XYZ99',
            'http://localhost/telebirr_payment/?id=ABC12XYZ99',
            'POST expected fields: {"settled_amount":135,"to":"Bank of Abyssinia"} with ?verify=1',
        ],
    ]);
}

try {
    $receiptUrl = ReceiptUrlBuilder::fromTransactionId($transactionId);
} catch (InvalidArgumentException $e) {
    jsonResponse(400, ['error' => $e->getMessage(), 'transaction_id' => $transactionId]);
}

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$verify = isset($_GET['verify']) && $_GET['verify'] === '1';

try {
    $html = TelebirrReceipt::loadReceipt(['receiptNo' => $transactionId]);
    $parsed = TelebirrReceipt::parseFromHTML($html);

    $payload = [
        'transaction_id' => $transactionId,
        'receipt_url'    => $receiptUrl,
        'parsed'         => $parsed,
    ];

    if ($verify) {
        $raw = file_get_contents('php://input') ?: '';
        $expected = json_decode($raw, true);
        if (!is_array($expected)) {
            $expected = [];
        }

        $checker = TelebirrReceipt::receipt($parsed, $expected);
        $payload['verification'] = [
            'verify_all' => $checker->verifyAll(),
            'matches_to' => $checker->verify(
                static fn(array $p, array $e) => $checker->equals($p['to'] ?? null, $e['to'] ?? null)
            ),
        ];
    }

    if ($debug) {
        $payload['html_length'] = strlen($html);
        $payload['html_preview'] = mb_substr($html, 0, 2000);
    }

    jsonResponse(200, $payload);
} catch (Throwable $e) {
    jsonResponse(502, [
        'transaction_id' => $transactionId,
        'receipt_url'    => $receiptUrl,
        'error'          => $e->getMessage(),
        'hint'           => 'Receipt host may only be reachable from an Ethiopian IP.',
    ]);
}
