<?php

namespace StoreCredit\Subscriber;

class Test
{
    public static function getSubscribed()
    {
        $mappedData[] = [
            'id'             => $order['id'],
            'currencyId'     => 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
            'salesChannelId' => '01943da76d8172c8b448d2d9763bafc0',
            'stateId'        => $statuses[$order['status']],
            'itemRounding'   => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'totalRounding'  => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR),
            'orderNumber'    => $order['order_number'],
            'orderDateTime'  => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'price'          => [
                'netPrice'        => (float)$order['amount_total_net'],
                'totalPrice'      => (float)$order['amount_total_net'],
                'rawTotal'        => (float)$order['amount_total_net'],
                'taxStatus'       => 'gross',
                'positionPrice'   => (float)$order['amount_total_net'],
                'calculatedTaxes' => [],
                'taxRules'        => []
            ],
            'shippingCosts' => [
                'quantity'        => 0,
                'unitPrice'       => 0,
                'totalPrice'      => 0,
                'calculatedTaxes' => [],
                'taxRules'        => []
            ],
            'orderCustomer' => [
                'customerId'     => $order['customer_id'],
                'customerNumber' => $order['customer_nr'],
                'email'          => $order['customer_email'],
                'firstName'      => $order['customer_firstname'],
                'lastName'       => $order['customer_lastname'],
            ],
            'addresses' => [
                [
                    'id'        => $order['shipping_address_id'],
                    'firstName' => $order['shipping_address_first_name'],
                    'lastName'  => $order['shipping_address_last_name'],
                    'zipcode'   => $order['shipping_address_zipcode'],
                    'city'      => $order['shipping_address_city'],
                    'street'    => $order['shipping_address_street'],
                    'countryId' => '01943da5d3bc7226b4a7c1cc5f37fb81',
                ]
            ],
            "billingAddressId" => $order['billing_address_id'],
            'billingAddress'   => [
                'id'        => $order['billing_address_id'],
                'firstName' => $order['billing_address_first_name'],
                'lastName'  => $order['billing_address_last_name'],
                'zipcode'   => $order['billing_address_zipcode'],
                'city'      => $order['billing_address_city'],
                'street'    => $order['billing_address_street'],
                'countryId' => '01943da5d3bc7226b4a7c1cc5f37fb81',
            ],
            'currencyFactor' => 0,
            'lineItems'      => $this->parseLineItems($order['line_items']),
            'deliveries'     => $mappedDeliveries,
            'transactions'   => $mappedTransactions,
        ];
    }
    protected function mapDeliveries(array $order, $stateId): array
    {
        $mappedDeliveries[] = [
            'stateId'              => $stateId,
            "shippingMethodId"     => "01943da5d44071e29293f9c2a4860938",
            "shippingOrderAddress" => [
                'salutationId' => "01943da5d27b735d9fe7d790c4cc7e54",
                'id'           => $order['shipping_address_id'],
                'firstName'    => $order['shipping_address_first_name'],
                'lastName'     => $order['shipping_address_last_name'],
                'zipcode'      => $order['shipping_address_zipcode'],
                'city'         => $order['shipping_address_city'],
                'street'       => $order['shipping_address_street'],
                'countryId'    => '01943da5d3bc7226b4a7c1cc5f37fb81',
            ],
            "shippingDateEarliest" => $order['order_date_time'],
            "shippingDateLatest"   => $order['order_date_time'],
            "shippingCosts"        => [
                "unitPrice"       => 0,
                "totalPrice"      => 0,
                "quantity"        => 1,
                "calculatedTaxes" => [],
                "taxRules"        => []
            ]
        ];

        return $mappedDeliveries;
    }

    protected function mapTransaction(array $order, $stateId): array
    {
        $unitPrice = (float)$order['amount_total_net'];

        return [
            [
                "paymentMethodId" => "01943da5d42b7308af90345f1c921992",
                "amount"          => [
                    "totalPrice"      => (float)$order['amount_total_net'],
                    "unitPrice"       => $unitPrice,
                    "quantity"        => 1,
                    "calculatedTaxes" => [
                        [
                            "tax"     => 0,
                            "taxRate" => 0,
                            "price"   => 0
                        ]
                    ],
                    "taxRules" => [
                        [
                            "taxRate"    => 0,
                            "percentage" => 0
                        ],
                    ]
                ],
                "stateId" => $stateId
            ]
        ];
    }
}
