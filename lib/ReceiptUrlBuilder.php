<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

final class ReceiptUrlBuilder
{
    public static function fromTransactionId(string $transactionId): string
    {
        $id = trim($transactionId);
        if (!preg_match(TRANSACTION_ID_PATTERN, $id)) {
            throw new InvalidArgumentException('Invalid transaction id format.');
        }

        return RECEIPT_BASE_URL . $id;
    }
}
