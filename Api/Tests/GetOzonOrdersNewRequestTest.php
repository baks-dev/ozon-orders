<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Ozon\Orders\Api\Tests;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Ozon\Orders\Api\GetOzonOrdersNewRequest;
use BaksDev\Ozon\Orders\UseCase\New\OzonMarketOrderDTO;
use BaksDev\Ozon\Type\Authorization\OzonAuthorizationToken;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * @group ozon-orders
 */
#[When(env: 'test')]
class GetOzonOrdersNewRequestTest extends KernelTestCase
{
    private static OzonAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        self::$Authorization = new OzonAuthorizationToken(
            new UserProfileUid(),
            $_SERVER['TEST_OZON_TOKEN'],
            $_SERVER['TEST_OZON_CLIENT'],
        );
    }

    public function testUseCase(): void
    {

        self::assertTrue(true);
        return;


        /** @var GetOzonOrdersNewRequest $GetOzonOrdersNewRequest */
        $GetOzonOrdersNewRequest = self::getContainer()->get(GetOzonOrdersNewRequest::class);
        $GetOzonOrdersNewRequest->TokenHttpClient(self::$Authorization);

        $orders = $GetOzonOrdersNewRequest->findAll(DateInterval::createFromDateString('5 days'));

        if($orders->valid() === false)
        {
            $order =
                [
                    "posting_number" => "05708065-0029-1",
                    "order_id" => 680420041,
                    "order_number" => "05708065-0029",

                    "status" => "awaiting_deliver",
                    "substatus" => "posting_awaiting_passport_data",

                    "delivery_method" => [
                        "id" => 21321684811000,
                        "name" => "Ozon Логистика самостоятельно, Красногорск",
                        "warehouse_id" => 21321684811000,
                        "warehouse" => "Стим Тойс Нахабино",
                        "tpl_provider_id" => 24,
                        "tpl_provider" => "Ozon Логистика"
                    ],

                    "tracking_number" => "",
                    "tpl_integration_type" => "ozon",
                    "in_process_at" => "2022-05-13T07:07:32Z",
                    "shipment_date" => "2022-05-13T10:00:00Z",
                    "delivering_date" => null,

                    "cancellation" => [
                        "cancel_reason_id" => 0,
                        "cancel_reason" => "",
                        "cancellation_type" => "",
                        "cancelled_after_ship" => false,
                        "affect_cancellation_rating" => false,
                        "cancellation_initiator" => ""
                    ],

                    "customer" => null,

                    "products" => [
                        [
                            "price" => "1390.000000",
                            "currency_code" => "RUB",
                            "offer_id" => "205953",
                            "name" => " Электронный конструктор PinLab Позитроник",
                            "sku" => 358924380,
                            "quantity" => 1,
                            "mandatory_mark" => [
                            ]
                        ]
                    ],

                    "addressee" => null,
                    "barcodes" => null,
                    "analytics_data" => null,
                    "financial_data" => null,
                    "is_express" => false,
                    "requirements" => [
                        "products_requiring_gtd" => [
                        ],
                        "products_requiring_country" => [
                        ],
                        "products_requiring_mandatory_mark" => [
                        ],
                        "products_requiring_jwn" => [
                        ]
                    ]
                ];


            $OzonMarketOrderDTO = new OzonMarketOrderDTO($order, new UserProfileUid());

            dd($OzonMarketOrderDTO);

            return;
        }


        foreach($orders as $order)
        {
            dd($order);
        }

        dd('GetOzonOrdersNewRequestTest');
    }


}
