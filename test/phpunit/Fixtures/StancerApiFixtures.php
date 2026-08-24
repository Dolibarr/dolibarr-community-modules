<?php

/**
 * API response fixtures for Stancer API testing
 *
 * These fixtures simulate real Stancer API responses
 */
class StancerApiFixtures
{
    // ========================================================================
    // CUSTOMERS
    // ========================================================================

    /**
     * Customer creation response
     */
    public static function customerCreated(): array
    {
        return [
            'id' => 'cust_9TycuMPH3xsPVE0n02IrI3L3',
            'created' => 1704067200,
            'email' => 'john.doe@example.com',
            'mobile' => '+33612345678',
            'name' => 'John Doe',
            'live_mode' => false,
        ];
    }

    /**
     * Customer fetch response
     */
    public static function customerFetched(): array
    {
        return self::customerCreated();
    }

    /**
     * Customer list response
     */
    public static function customerList(): array
    {
        return [
            'customers' => [
                self::customerCreated(),
                [
                    'id' => 'cust_ABC123456789',
                    'created' => 1704153600,
                    'email' => 'jane.doe@example.com',
                    'name' => 'Jane Doe',
                    'live_mode' => false,
                ],
            ],
            'range' => [
                'has_more' => false,
                'limit' => 10,
            ],
        ];
    }

    // ========================================================================
    // PAYMENTS
    // ========================================================================

    /**
     * Payment creation response (checkout)
     */
    public static function paymentCreated(): array
    {
        return [
            'id' => 'paym_4kM8Kv5X0HJ8vLqF',
            'created' => 1704067200,
            'amount' => 2500,
            'currency' => 'eur',
            'status' => 'pending',
            'customer' => 'cust_9TycuMPH3xsPVE0n02IrI3L3',
            'order_id' => 'order_123',
            'unique_id' => 'unique_456',
            'description' => 'Test payment',
            'live_mode' => false,
        ];
    }

    /**
     * Payment captured response
     */
    public static function paymentCaptured(): array
    {
        $payment = self::paymentCreated();
        $payment['status'] = 'captured';
        $payment['captured'] = 1704067300;
        return $payment;
    }

    /**
     * Payment authorized response
     */
    public static function paymentAuthorized(): array
    {
        $payment = self::paymentCreated();
        $payment['status'] = 'authorized';
        return $payment;
    }

    /**
     * Payment list response
     */
    public static function paymentList(): array
    {
        return [
            'payments' => [
                self::paymentCaptured(),
                [
                    'id' => 'paym_ABC123456789',
                    'created' => 1704153600,
                    'amount' => 5000,
                    'currency' => 'eur',
                    'status' => 'captured',
                    'live_mode' => false,
                ],
            ],
            'range' => [
                'has_more' => false,
                'limit' => 10,
            ],
        ];
    }

    /**
     * Payment response with expanded objects (customer, card, sepa as full objects)
     * This is what the API returns when objects are expanded instead of just IDs
     */
    public static function paymentWithExpandedObjects(): array
    {
        return [
            'id' => 'paym_4kM8Kv5X0HJ8vLqF',
            'created' => 1704067200,
            'amount' => 2500,
            'currency' => 'eur',
            'status' => 'captured',
            'customer' => [
                'id' => 'cust_9TycuMPH3xsPVE0n02IrI3L3',
                'created' => 1674801695,
                'email' => 'john.doe@example.com',
                'name' => 'John Doe',
                'mobile' => '+33612345678',
                'live_mode' => true,
                'country' => 'FR',
                'date_birth' => null,
                'external_id' => null,
                'legal_id' => null,
            ],
            'card' => [
                'id' => 'card_xognFbZs935LMKJp',
                'created' => 1704067200,
                'last4' => '9920',
                'exp_month' => 12,
                'exp_year' => 2028,
                'brand' => 'mastercard',
                'name' => 'John Doe',
                'live_mode' => true,
                'country' => 'FR',
                'funding' => null,
                'nature' => 'corporate',
                'network' => 'national',
                'preferred_network' => 'national',
                'zip_code' => null,
                'external_id' => null,
            ],
            'sepa' => [
                'id' => 'sepa_ABC123456789',
                'created' => 1704067200,
                'last4' => '1234',
                'bic' => 'BNPAFRPP',
                'name' => 'John Doe',
                'mandate' => 'MANDATE-001',
                'live_mode' => true,
            ],
            'order_id' => 'FA2602-4742',
            'unique_id' => 'CUS=1478.INV=7597',
            'description' => 'Test payment with expanded objects',
            'method' => 'card',
            'response' => '00',
            'capture' => true,
            'refunds' => [],
            'live_mode' => true,
        ];
    }

    /**
     * Payment response with string IDs only (classic scenario)
     */
    public static function paymentWithStringIds(): array
    {
        return [
            'id' => 'paym_4kM8Kv5X0HJ8vLqF',
            'created' => 1704067200,
            'amount' => 2500,
            'currency' => 'eur',
            'status' => 'captured',
            'customer' => 'cust_9TycuMPH3xsPVE0n02IrI3L3',
            'card' => 'card_xognFbZs935LMKJp',
            'sepa' => 'sepa_ABC123456789',
            'order_id' => 'FA2602-4742',
            'unique_id' => 'CUS=1478.INV=7597',
            'description' => 'Test payment with string IDs',
            'method' => 'card',
            'response' => '00',
            'capture' => true,
            'refunds' => [],
            'live_mode' => true,
        ];
    }

    // ========================================================================
    // REFUNDS
    // ========================================================================

    /**
     * Refund creation response
     */
    public static function refundCreated(): array
    {
        return [
            'id' => 'rfnd_8HkP2L4mN6wR',
            'created' => 1704067200,
            'amount' => 1000,
            'currency' => 'eur',
            'payment' => 'paym_4kM8Kv5X0HJ8vLqF',
            'status' => 'to_refund',
            'live_mode' => false,
        ];
    }

    /**
     * Refund completed response
     */
    public static function refundCompleted(): array
    {
        $refund = self::refundCreated();
        $refund['status'] = 'refunded';
        return $refund;
    }

    // ========================================================================
    // PAYOUTS
    // ========================================================================

    /**
     * Payout response
     */
    public static function payoutFetched(): array
    {
        return [
            'id' => 'pout_1A2B3C4D5E6F',
            'created' => 1704067200,
            'amount' => 100000,
            'currency' => 'eur',
            'status' => 'sent',
            'date_bank' => 1704153600,
            'live_mode' => false,
        ];
    }

    /**
     * Payout list response
     */
    public static function payoutList(): array
    {
        return [
            'payouts' => [
                self::payoutFetched(),
            ],
            'range' => [
                'has_more' => false,
                'limit' => 10,
            ],
        ];
    }

    // ========================================================================
    // CARDS
    // ========================================================================

    /**
     * Card creation response
     */
    public static function cardCreated(): array
    {
        return [
            'id' => 'card_xognFbZs935LMKJp',
            'created' => 1704067200,
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2025,
            'brand' => 'visa',
            'name' => 'John Doe',
            'live_mode' => false,
        ];
    }

    // ========================================================================
    // SEPA
    // ========================================================================

    /**
     * SEPA creation response
     */
    public static function sepaCreated(): array
    {
        return [
            'id' => 'sepa_ABC123456789',
            'created' => 1704067200,
            'last4' => '1234',
            'bic' => 'BNPAFRPP',
            'name' => 'John Doe',
            'mandate' => 'MANDATE-001',
            'live_mode' => false,
        ];
    }

    // ========================================================================
    // DISPUTES
    // ========================================================================

    /**
     * Dispute response
     */
    public static function disputeFetched(): array
    {
        return [
            'id' => 'dspt_1A2B3C4D5E6F',
            'created' => 1704067200,
            'amount' => 2500,
            'currency' => 'eur',
            'payment' => 'paym_4kM8Kv5X0HJ8vLqF',
            'status' => 'pending',
            'live_mode' => false,
        ];
    }

    // ========================================================================
    // ERRORS
    // ========================================================================

    /**
     * Invalid request error
     */
    public static function errorInvalidRequest(): array
    {
        return [
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'The request was invalid.',
            ],
        ];
    }

    /**
     * Not found error
     */
    public static function errorNotFound(): array
    {
        return [
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Resource not found.',
            ],
        ];
    }

    /**
     * Authentication error
     */
    public static function errorAuthentication(): array
    {
        return [
            'error' => [
                'type' => 'authentication_error',
                'message' => 'Invalid API key.',
            ],
        ];
    }

    /**
     * Rate limit error
     */
    public static function errorRateLimit(): array
    {
        return [
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Too many requests.',
            ],
        ];
    }

    // ========================================================================
    // PAYOUTS - FULL API RESPONSE (for fillDataFromApi testing)
    // ========================================================================

    /**
     * Full payout API response with all fields for fillDataFromApi testing
     */
    public static function payoutFullApiResponse(): array
    {
        return [
            'id' => 'pout_1A2B3C4D5E6F',
            'created' => 1704067200,
            'payments' => [
                'amount' => 95000,
            ],
            'fees' => 5000,
            'total' => 100000,
            'currency' => 'eur',
            'status' => 'sent',
            'date_bank' => 1704153600,
            'date_paym' => 1704240000,
            'details' => ['paym_abc', 'paym_def'],
            'statement_description' => 'STANCER PAYOUT',
            'live_mode' => true,
        ];
    }

    /**
     * Real-world payout API v2 response with disputes and refunds
     * Source: pout_CyJRD6BAIJbtHIv64CsXIv2W (reference 1116852)
     *
     * Breakdown:
     *   payments.amount = 20400  (CB 18000 + SEPA 2400)
     *   refunds.amount  = 0
     *   disputes.amount = -1200  (1 SEPA dispute, fees 600)
     *   fees            = 885    (includes dispute fees)
     *   fees_vat        = 177
     *   amount (net)    = 18138  (what is actually received on the bank account)
     */
    public static function payoutWithDisputesApiResponse(): array
    {
        return [
            'id' => 'pout_CyJRD6BAIJbtHIv64CsXIv2W',
            'reference' => '1116852',
            'currency' => 'eur',
            'payments' => [
                'amount' => 20400,
                'method' => [
                    'card' => ['amount' => 18000, 'count' => 1, 'fees' => 431],
                    'sepa' => ['amount' => 2400,  'count' => 2, 'fees' => 31],
                ],
            ],
            'refunds' => [
                'amount' => 0,
                'method' => ['card' => null, 'sepa' => null],
            ],
            'disputes' => [
                'amount' => -1200,
                'method' => [
                    'card' => null,
                    'sepa' => ['amount' => -1200, 'count' => 1, 'fees' => 600],
                ],
            ],
            'amount'   => 18138,
            'fees'     => 885,
            'fees_vat' => 177,
            'status'   => 'paid',
            'date'     => 1773992423,
            'date_bank' => '2026-03-26',
        ];
    }

    // ========================================================================
    // API V2 - ADDRESSES
    // ========================================================================

    /**
     * Address creation response (v2)
     */
    public static function addressCreated(): array
    {
        return [
            'id' => 'addr_1A2B3C4D5E6F',
            'created' => 1704067200,
            'line1' => '123 Main Street',
            'line2' => 'Apt 4B',
            'city' => 'Paris',
            'zip' => '75001',
            'country' => 'FR',
            'live_mode' => false,
        ];
    }

    // ========================================================================
    // API V2 - MANDATES
    // ========================================================================

    /**
     * Mandate creation response (v2)
     */
    public static function mandateCreated(): array
    {
        return [
            'id' => 'sdmm_1A2B3C4D5E6F',
            'created' => 1704067200,
            'status' => 'active',
            'sepa' => 'sepa_ABC123456789',
            'customer' => 'cust_9TycuMPH3xsPVE0n02IrI3L3',
            'live_mode' => false,
        ];
    }
}
