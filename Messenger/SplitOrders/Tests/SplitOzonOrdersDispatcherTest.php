<?php
/*
 *  Copyright 2026.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Ozon\Orders\Messenger\SplitOrders\Tests;

use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Core\Type\Field\InputField;
use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Delivery\Repository\CurrentDeliveryEvent\CurrentDeliveryEventInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Tests\OrderNewTest;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Messenger\SplitOrders\SplitOzonOrderMessage;
use BaksDev\Ozon\Orders\Type\ProfileType\TypeProfileFbsOzon;
use BaksDev\Ozon\Orders\UseCase\New\Products\NewOzonOrderProductDTO;
use BaksDev\Ozon\Type\Authorization\OzonAuthorizationToken;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Products\Stocks\UseCase\Admin\Package\Tests\PackageProductStockTest;
use BaksDev\Users\Address\Services\GeocodeAddressParser;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileGps\UserProfileGpsInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\Tests\UserNewUserProfileHandleTest;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

#[Group('ozon-orders')]
#[When(env: 'test')]
class SplitOzonOrdersDispatcherTest extends KernelTestCase
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
            TypeProfileFbsOzon::TYPE,
            $_SERVER['TEST_OZON_CLIENT'],
            $_SERVER['TEST_OZON_WAREHOUSE'],
            '10',
            0,
            false,
            false,
        );
    }

    public function testUseCase(): void
    {
        self::assertTrue(true);

        /** @var GetOzonOrderInfoRequest $GetOzonOrderInfoRequest */
        $GetOzonOrderInfoRequest = self::getContainer()->get(GetOzonOrderInfoRequest::class);
        $GetOzonOrderInfoRequest->TokenHttpClient(self::$Authorization);

        $NewOzonOrderDTO = $GetOzonOrderInfoRequest->find('30873379-0157-1');

        $products = null;

        /**
         * @var NewOzonOrderProductDTO $NewOrderProductDTO
         * Разделяем заказа на отдельные машиноместа
         */
        foreach($NewOzonOrderDTO->getProduct() as $NewOrderProductDTO)
        {
            $pack = $NewOrderProductDTO->getPrice()->getTotal();

            for($i = 1; $i <= $pack; $i++)
            {
                $products[]['products'][] = [
                    "product_id" => $NewOrderProductDTO->getSku(),
                    "quantity" => 1, // всегда делим заказ на одно машиноместо
                ];
            }
        }


        /** @var MessageDispatchInterface $MessageDispatch */
        $MessageDispatch = self::getContainer()->get(MessageDispatchInterface::class);

        $SplitOzonOrderMessage = new SplitOzonOrderMessage(
            profile: new UserProfileUid(),
            token: new OzonTokenUid('01978694-98ff-7e22-ae35-8b621ffbe981'),
            order: '30873379-0157-1',
        );

        $MessageDispatch->dispatch($SplitOzonOrderMessage);
    }
}
