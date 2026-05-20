<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

/**
 * PHP port of telebirr-receipt (parseFromHTML, loadReceipt, receipt verification).
 * Extended for the live receipt HTML layout (invoice table, service fee, stamp duty).
 */
final class TelebirrReceipt
{
    private const NAMES = [
        'የከፋይስም/payername',
        'የከፋይቴሌብርቁ./payertelebirrno.',
        'የከፋይአካውንትአይነት/payeraccounttype',
        'የገንዘብተቀባይስም/creditedpartyname',
        'የገንዘብተቀባይቴሌብርቁ./creditedpartyaccountno',
        'የክፍያውሁኔታ/transactionstatus',
        'የባንክአካውንትቁጥር/bankaccountnumber',
        'የክፍያቁጥር/receiptno.',
        'የክፍያቀን/paymentdate',
        'የተከፈለውመጠን/settledamount',
        'ቅናሽ/discountamount',
        '15%ቫት/vat',
        'ጠቅላላየተክፈለ/totalamountpaid',
        'የገንዘቡልክበፊደል/totalamountinword',
        'የክፍያዘዴ/paymentmode',
        'የክፍያምክንያት/paymentreason',
        'የክፍያመንገድ/paymentchannel',
    ];

    private const KEYS = [
        'payer_name',
        'payer_phone',
        'payer_acc_type',
        'credited_party_name',
        'credited_party_acc_no',
        'transaction_status',
        'bank_acc_no',
        'receiptNo',
        'date',
        'settled_amount',
        'discount_amount',
        'vat_amount',
        'total_amount',
        'amount_in_word',
        'payment_mode',
        'payment_reason',
        'payment_channel',
    ];

    /** Maps normalized label suffix → field key (telebirr-receipt + live page extras). */
    private const LABEL_SUFFIX_ALIASES = [
        '/payername'              => 'payer_name',
        '/payertelebirrno.'       => 'payer_phone',
        '/payeraccounttype'       => 'payer_acc_type',
        '/creditedpartyname'      => 'credited_party_name',
        '/creditedpartyaccountno' => 'credited_party_acc_no',
        '/transactionstatus'      => 'transaction_status',
        '/bankaccountnumber'      => 'bank_acc_no',
        '/invoiceno.'             => 'receiptNo',
        '/paymentdate'            => 'date',
        '/settledamount'          => 'settled_amount',
        '/stampduty'              => 'stamp_duty',
        '/discountamount'         => 'discount_amount',
        '/servicefeevat'          => 'vat_amount',
        '/totalpaidamount'        => 'total_amount',
        '/totalamountinword'      => 'amount_in_word',
        '/paymentmode'            => 'payment_mode',
        '/paymentreason'          => 'payment_reason',
        '/paymentchannel'         => 'payment_channel',
        '/customernote'           => 'customer_note',
    ];

    public function __construct(
        private array $parsedFields = [],
        private array $preDefinedFields = []
    ) {
    }

    /** @param array<string, mixed> $parsedFields @param array<string, mixed> $preDefinedFields */
    public static function receipt(array $parsedFields = [], array $preDefinedFields = []): self
    {
        return new self($parsedFields, $preDefinedFields);
    }

    public function equals(mixed $a, mixed $b): bool
    {
        return (is_string($a) && is_string($b) || is_int($a) && is_int($b) || is_float($a) && is_float($b))
            && $a === $b;
    }

    /** @param callable(array<string, mixed>, array<string, mixed>): bool $callback */
    public function verify(callable $callback): bool
    {
        return $callback($this->parsedFields, $this->preDefinedFields);
    }

    /** @param list<string> $doNotCompare */
    public function verifyAll(array $doNotCompare = []): bool
    {
        if (count($this->parsedFields) <= 0) {
            return false;
        }

        foreach ($this->parsedFields as $key => $value) {
            if (in_array($key, $doNotCompare, true)) {
                continue;
            }
            if (!$this->equals($value, $this->preDefinedFields[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $fieldNames */
    public function verifyOnly(array $fieldNames = []): bool
    {
        if (count($fieldNames) <= 0) {
            return false;
        }

        foreach ($fieldNames as $key) {
            if (!$this->equals($this->parsedFields[$key] ?? null, $this->preDefinedFields[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string|float|null>
     */
    public static function parseFromHTML(string $str): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($str, LIBXML_NOERROR | LIBXML_NOWARNING);

        $fields = self::parseBankReferenceLabel($dom);
        $fields = array_merge($fields, self::parseFromTableCells($dom));

        return $fields;
    }

    /**
     * Bank transfer receipts expose account + name on #paid_reference_number.
     *
     * @return array<string, string>
     */
    private static function parseBankReferenceLabel(DOMDocument $dom): array
    {
        $node = $dom->getElementById('paid_reference_number');
        if ($node === null) {
            return [];
        }

        $raw = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
        if ($raw === '') {
            return [];
        }

        return [
            'to'          => trim(preg_replace('/^[0-9]+/u', '', $raw) ?? ''),
            'bank_acc_no' => trim(preg_replace('/[^0-9]/u', '', $raw) ?? ''),
        ];
    }

    /**
     * @return array<string, string|float|null>
     */
    private static function parseFromTableCells(DOMDocument $dom): array
    {
        $td = [];
        foreach ($dom->getElementsByTagName('td') as $node) {
            $td[] = $node;
        }

        $fields = [];
        $tdCount = count($td);

        $getNextValue = static function (int $index) use ($td, $tdCount): string {
            if ($index + 1 >= $tdCount) {
                return '';
            }
            $text = $td[$index + 1]->textContent ?? '';
            $text = preg_replace('/[\n\r\t]/u', '', $text) ?? '';
            $text = preg_replace('/\s+/u', ' ', $text) ?? '';

            return trim($text);
        };

        foreach ($td as $index => $cell) {
            $normalized = self::normalizeLabel($cell->textContent ?? '');
            if ($normalized === '') {
                continue;
            }

            $fieldKey = self::resolveFieldKey($normalized);
            if ($fieldKey === null) {
                continue;
            }

            // Service fee (not VAT): label cell may use colspan; value is still next <td>.
            if ($fieldKey === 'service_fee') {
                $fields['service_fee'] = self::parseBirrAmount($getNextValue($index));
                continue;
            }

            // Invoice row: three headers then one row of values (receipt, date, amount).
            $offset = self::valueOffsetForField($fieldKey, $normalized);
            $nextValue = $getNextValue($index + $offset);

            if ($fieldKey === 'bank_acc_no' && !isset($fields['bank_acc_no'])) {
                $fields['to'] = trim(preg_replace('/^[0-9]+/u', '', $nextValue) ?? '');
                $fields['bank_acc_no'] = trim(preg_replace('/[^0-9]/u', '', $nextValue) ?? '');
            } elseif (self::isAmountField($fieldKey)) {
                $fields[$fieldKey] = self::parseBirrAmount($nextValue);
            } else {
                $fields[$fieldKey] = $nextValue !== '' ? $nextValue : null;
            }
        }

        return $fields;
    }

    private static function normalizeLabel(string $text): string
    {
        $s = preg_replace('/[\p{Z}\s]/u', '', $text) ?? '';
        $s = preg_replace('/&nbsp;/i', '', $s) ?? '';

        return mb_strtolower(trim($s));
    }

    private static function resolveFieldKey(string $normalized): ?string
    {
        $legacyIndex = array_search($normalized, self::NAMES, true);
        if ($legacyIndex !== false) {
            return self::KEYS[$legacyIndex];
        }

        if (str_ends_with($normalized, '/servicefee') && !str_ends_with($normalized, '/servicefeevat')) {
            return 'service_fee';
        }

        foreach (self::LABEL_SUFFIX_ALIASES as $suffix => $key) {
            if (str_ends_with($normalized, $suffix)) {
                return $key;
            }
        }

        return null;
    }

    private static function valueOffsetForField(string $fieldKey, string $normalized): int
    {
        // telebirr-receipt: receipt / date / settled header row uses +2 skip on old layout.
        if (in_array($fieldKey, ['receiptNo', 'date', 'settled_amount'], true)) {
            $legacyIndex = array_search($normalized, self::NAMES, true);
            if ($legacyIndex !== false && $legacyIndex > 6 && $legacyIndex <= 9) {
                return 2;
            }
            if (str_ends_with($normalized, '/invoiceno.') || str_ends_with($normalized, '/paymentdate')) {
                return 2;
            }
        }

        return 0;
    }

    private static function isAmountField(string $key): bool
    {
        return in_array($key, [
            'settled_amount', 'discount_amount', 'vat_amount', 'total_amount',
            'stamp_duty', 'service_fee',
        ], true);
    }

    private static function parseBirrAmount(string $value): float
    {
        $num = preg_replace('/birr/iu', '', $value) ?? $value;

        return (float) trim($num);
    }

    /**
     * @param array{receiptNo?: string, fullUrl?: string} $options
     */
    public static function loadReceipt(array $options): string
    {
        $receiptNo = $options['receiptNo'] ?? null;
        $fullUrl = $options['fullUrl'] ?? null;

        $url = $receiptNo !== null && $receiptNo !== ''
            ? RECEIPT_BASE_URL . $receiptNo
            : ($fullUrl ?? '');

        if ($url === '') {
            throw new InvalidArgumentException('receiptNo or fullUrl is required.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => FETCH_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        $data = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException($error);
        }

        if ($data === false || $httpCode < 200 || $httpCode >= 400) {
            throw new RuntimeException('HTTP ' . $httpCode);
        }

        return $data;
    }
}
