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
use BaksDev\Core\Type\Field\InputField;
use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Delivery\Repository\CurrentDeliveryEvent\CurrentDeliveryEventInterface;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Tests\OrderNewTest;
use BaksDev\Ozon\Orders\Api\GetOzonOrdersNewRequest;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryDbsOzon;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderHandler;
use BaksDev\Ozon\Orders\UseCase\New\Products\NewOrderProductDTO;
use BaksDev\Ozon\Orders\UseCase\New\User\Delivery\Field\OrderDeliveryFieldDTO;
use BaksDev\Ozon\Type\Authorization\OzonAuthorizationToken;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Products\Stocks\UseCase\Admin\Package\Tests\PackageProductStockTest;
use BaksDev\Users\Address\Services\GeocodeAddressParser;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileGps\UserProfileGpsInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\Tests\UserEditUserProfileHandleTest;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\Tests\UserNewUserProfileHandleTest;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
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
        UserNewUserProfileHandleTest::setUpBeforeClass();

        OrderNewTest::setUpBeforeClass();

        PackageProductStockTest::setUpBeforeClass();

        self::$Authorization = new OzonAuthorizationToken(
            new UserProfileUid('018d464d-c67a-7285-8192-7235b0510924'),
            $_SERVER['TEST_OZON_TOKEN'],
            $_SERVER['TEST_OZON_CLIENT'],
        );
    }

    public function testUseCase(): void
    {

        //self::assertTrue(true);
        ///return;


        /** @var GetOzonOrdersNewRequest $GetOzonOrdersNewRequest */
        $GetOzonOrdersNewRequest = self::getContainer()->get(GetOzonOrdersNewRequest::class);
        $GetOzonOrdersNewRequest->TokenHttpClient(self::$Authorization);

        $orders = $GetOzonOrdersNewRequest->findAll(DateInterval::createFromDateString('5 days'));


        /** Сервисы DI */

        /** @var UserProfileGpsInterface $UserProfileGpsInterface */
        $UserProfileGpsInterface = self::getContainer()->get(UserProfileGpsInterface::class);

        /** @var GeocodeAddressParser $GeocodeAddressParser */
        $GeocodeAddressParser = self::getContainer()->get(GeocodeAddressParser::class);

        /** @var UserByUserProfileInterface $UserByUserProfile */
        $UserByUserProfile = self::getContainer()->get(UserByUserProfileInterface::class);

        /** @var ProductConstByArticleInterface $ProductConstByArticle */
        $ProductConstByArticle = self::getContainer()->get(ProductConstByArticleInterface::class);

        /** @var CurrentDeliveryEventInterface $CurrentDeliveryEvent */
        $CurrentDeliveryEvent = self::getContainer()->get(CurrentDeliveryEventInterface::class);

        /** @var NewOzonOrderHandler $NewOzonOrderHandler */
        $NewOzonOrderHandler = self::getContainer()->get(NewOzonOrderHandler::class);


        /** @var FieldByDeliveryChoiceInterface $FieldByDeliveryChoice */
        $FieldByDeliveryChoice = self::getContainer()->get(FieldByDeliveryChoiceInterface::class);


        if($orders->valid())
        {
            /** @var NewOzonOrderDTO $OzonMarketOrderDTO */
            foreach($orders as $OzonMarketOrderDTO)
            {
                $NewOrderInvariable = $OzonMarketOrderDTO->getInvariable();

                $UserProfileUid = $NewOrderInvariable->getProfile();

                /**
                 * Присваиваем идентификатор пользователя @UserUid по идентификатору профиля @UserProfileUid
                 */
                $User = $UserByUserProfile->forProfile($UserProfileUid)->findUser();

                if($User === false)
                {
                    /*$this->logger->critical(sprintf(
                        'Пользователь профиля %s для заказа %s не найден',
                        $NewOrderInvariable->getProfile(),
                        $NewOrderInvariable->getNumber()
                    ));

                    return 'Пользователь по профилю не найден';*/
                }

                $NewOrderInvariable->setUsr($User->getId());

                $OrderUserDTO = $OzonMarketOrderDTO->getUsr();

                $OrderDeliveryDTO = $OrderUserDTO->getDelivery();

                /**
                 * Если заказ DBS - адрес присваивается из запроса
                 * Если заказ FBS - адрес доставки сам склад, получить и присвоить
                 */

                // доставка FBS - Доставка Ozon
                if($OrderDeliveryDTO->getDelivery()->equals(TypeDeliveryFbsOzon::TYPE))
                {
                    $address = $UserProfileGpsInterface->findUserProfileGps($UserProfileUid);

                    $OrderDeliveryDTO->setAddress($address['location']);

                    isset($address['latitude']) ? $OrderDeliveryDTO->setLatitude(new GpsLatitude($address['latitude'])) : false;
                    isset($address['longitude']) ? $OrderDeliveryDTO->setLongitude(new GpsLongitude($address['longitude'])) : false;
                }

                /** Определяем геолокацию, если не указана */
                if(is_null($OrderDeliveryDTO->getLatitude()) || is_null($OrderDeliveryDTO->getLongitude()))
                {
                    $GeocodeAddressDTO = $GeocodeAddressParser->getGeocode($OrderDeliveryDTO->getAddress());

                    $OrderDeliveryDTO->setAddress($GeocodeAddressDTO->getAddress());
                    $OrderDeliveryDTO->setLatitude($GeocodeAddressDTO->getLatitude());
                    $OrderDeliveryDTO->setLongitude($GeocodeAddressDTO->getLongitude());
                }


                /**
                 * Присваиваем активное событие доставки
                 */

                $DeliveryEvent = $CurrentDeliveryEvent->get($OrderDeliveryDTO->getDelivery());
                $OrderDeliveryDTO->setEvent($DeliveryEvent?->getId());


                /**
                 * Определяем свойства доставки и присваиваем адрес
                 */

                $fields = $FieldByDeliveryChoice->fetchDeliveryFields($OrderDeliveryDTO->getDelivery());

                $address_field = array_filter($fields, function ($v) {
                    /** @var InputField $InputField */
                    return $v->getType()->getType() === 'address_field';
                });

                $address_field = current($address_field);

                if($address_field)
                {
                    $OrderDeliveryFieldDTO = new OrderDeliveryFieldDTO();
                    $OrderDeliveryFieldDTO->setField($address_field);
                    $OrderDeliveryFieldDTO->setValue($OrderDeliveryDTO->getAddress());
                    $OrderDeliveryDTO->addField($OrderDeliveryFieldDTO);
                }


                /**
                 * Получаем события продукции
                 * @var NewOrderProductDTO $product
                 */
                foreach($OzonMarketOrderDTO->getProduct() as $product)
                {
                    $ProductData = $ProductConstByArticle->find($product->getArticle());

                    if(!$ProductData)
                    {
                        $error = sprintf('Артикул товара %s не найден', $product->getArticle());
                        throw new InvalidArgumentException($error);
                    }

                    $product
                        ->setProduct($ProductData->getEvent())
                        ->setOffer($ProductData->getOffer())
                        ->setVariation($ProductData->getVariation())
                        ->setModification($ProductData->getModification());
                }


                $handle = $NewOzonOrderHandler->handle($OzonMarketOrderDTO);
                dd($handle);
            }
        }

        dd(6465454);


        dd($orders);

        if($orders->valid() === false)
        {
            $jayParsedAry = [
                "result" => [
                    "postings" => [
                        [
                            "posting_number" => "34326946-0157-1",
                            "order_id" => 25048162466,
                            "order_number" => "34326946-0157",
                            "status" => "awaiting_packaging",
                            "delivery_method" => [
                                "id" => 1020002142177000,
                                "name" => "Доставка Ozon самостоятельно, Москва",
                                "warehouse_id" => 1020002142177000,
                                "warehouse" => "Митино",
                                "tpl_provider_id" => 24,
                                "tpl_provider" => "Доставка Ozon"
                            ],
                            "tracking_number" => "",
                            "tpl_integration_type" => "ozon",
                            "in_process_at" => "2024-10-06T21:48:24Z",
                            "shipment_date" => "2024-10-08T14:59:00Z",
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
                                    "price" => "6210.0000",
                                    "offer_id" => "TH202-18-225-40-92Y",
                                    "name" => "Летние шины Triangle EffeXSport XL TH202 225/40 R18 92Y для легковых автомобилей",
                                    "sku" => 1713319288,
                                    "quantity" => 1,
                                    "mandatory_mark" => [],
                                    "currency_code" => "RUB"
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
                                "products_requiring_rnpt" => [
                                ],
                                "products_requiring_jw_uin" => [
                                ]
                            ],
                            "parent_posting_number" => "",
                            "available_actions" => [
                                "cancel",
                                "product_cancel",
                                "ship",
                                "ship_async"
                            ],
                            "multi_box_qty" => 1,
                            "is_multibox" => false,
                            "substatus" => "posting_created",
                            "prr_option" => "",
                            "quantum_id" => 0
                        ]
                    ],
                    "has_next" => false
                ]
            ];


            $OzonMarketOrderDTO = new NewOzonOrderDTO($order, new UserProfileUid());

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
