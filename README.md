# telebirr-receipt-php

PHP script to fetch, parse, and verify Telebirr payment receipts using the public receipt page.

## Receipt URL

```
https://transactioninfo.ethiotelecom.et/receipt/{transaction_id}
```

The `transaction_id` is the code from the Telebirr SMS (e.g. `ABC12XYZ99`).

## Requirements

- PHP 8.1+ (`curl`, `dom`, `mbstring`)
- Apache with `mod_rewrite` (or equivalent)

## Setup

1. Clone into your web root.
2. Enable `mod_rewrite` and allow `.htaccess`.

## API

```
GET /telebirr_payment/ABC12XYZ99
GET /telebirr_payment/?id=ABC12XYZ99
```

**Example response:**

```json
{
  "transaction_id": "ABC12XYZ99",
  "receipt_url": "https://transactioninfo.ethiotelecom.et/receipt/ABC12XYZ99",
  "parsed": {
    "receiptNo": "ABC12XYZ99",
    "settled_amount": 500,
    "total_amount": 510,
    "payer_name": "Demo Payer",
    "credited_party_name": "Demo Bank",
    "bank_acc_no": "1000123456",
    "to": "Demo Recipient",
    "transaction_status": "Completed"
  }
}
```

### Verify payment

```
GET /telebirr_payment/ABC12XYZ99?verify=1
Content-Type: application/json

{"settled_amount": 500, "receiptNo": "ABC12XYZ99", "to": "Demo Recipient"}
```

## Library usage

```php
require_once __DIR__ . '/lib/TelebirrReceipt.php';

$html = TelebirrReceipt::loadReceipt(['receiptNo' => 'ABC12XYZ99']);
$parsed = TelebirrReceipt::parseFromHTML($html);

$checker = TelebirrReceipt::receipt($parsed, [
    'settled_amount' => 500,
    'receiptNo'      => 'ABC12XYZ99',
]);

$checker->verifyAll();
```

## Parsed fields

`receiptNo`, `date`, `settled_amount`, `service_fee`, `vat_amount`, `total_amount`, `payer_name`, `payer_phone`, `credited_party_name`, `bank_acc_no`, `to`, `transaction_status`, `payment_mode`, `payment_reason`, `payment_channel`, and others depending on transaction type.

## Notes

- Receipt HTML may change; test after updates.
- The receipt server may only be reachable from Ethiopian networks.
- Not affiliated with Ethio Telecom or Telebirr.

## Disclaimer

Provided as-is without warranty. You are responsible for how you use this tool and for complying with applicable laws and terms of service.
