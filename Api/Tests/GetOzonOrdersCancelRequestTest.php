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

use BaksDev\Core\Type\Field\InputField;
use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Delivery\Repository\CurrentDeliveryEvent\CurrentDeliveryEventInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Tests\OrderNewTest;
use BaksDev\Ozon\Orders\Api\GetOzonOrdersByStatusRequest;
use BaksDev\Ozon\Orders\UseCase\Cancel\CancelOzonOrderDTO;
use BaksDev\Ozon\Type\Authorization\OzonAuthorizationToken;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Products\Stocks\UseCase\Admin\Package\Tests\PackageProductStockTest;
use BaksDev\Users\Address\Services\GeocodeAddressParser;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileGps\UserProfileGpsInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\Tests\UserNewUserProfileHandleTest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * @group ozon-orders
 */
#[When(env: 'test')]
class GetOzonOrdersCancelRequestTest extends KernelTestCase
{
    private static OzonAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        OrderNewTest::setUpBeforeClass();
        PackageProductStockTest::setUpBeforeClass();
        UserNewUserProfileHandleTest::setUpBeforeClass();

        self::$Authorization = new OzonAuthorizationToken(
            new UserProfileUid('018d464d-c67a-7285-8192-7235b0510924'),
            $_SERVER['TEST_OZON_TOKEN'],
            $_SERVER['TEST_OZON_CLIENT'],
            $_SERVER['TEST_OZON_WAREHOUSE'],
        );
    }

    public function testUseCase(): void
    {
        self::assertTrue(true);

        /** @var GetOzonOrdersByStatusRequest $GetOzonOrdersByStatusRequest */
        $GetOzonOrdersByStatusRequest = self::getContainer()->get(GetOzonOrdersByStatusRequest::class);
        $GetOzonOrdersByStatusRequest->TokenHttpClient(self::$Authorization);

        $orders = $GetOzonOrdersByStatusRequest
            ->interval('5 days')
            ->findAllCancel();

        if($orders->valid())
        {
            foreach($orders as $order)
            {
                self::assertInstanceOf(CancelOzonOrderDTO::class, $order);
                //dump($order);
            }
        }
    }
}
