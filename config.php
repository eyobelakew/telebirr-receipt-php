<?php

declare(strict_types=1);

/** Base URL for Ethio Telecom transaction receipts (append transaction id). */
const RECEIPT_BASE_URL = 'https://transactioninfo.ethiotelecom.et/receipt/';

/** Transaction id: letters and digits (e.g. ABC12XYZ99). */
const TRANSACTION_ID_PATTERN = '/^[A-Za-z0-9]{6,32}$/';

/** HTTP fetch timeout in seconds. */
const FETCH_TIMEOUT_SECONDS = 30;
