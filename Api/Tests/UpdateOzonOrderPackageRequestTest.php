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
use BaksDev\DeliveryTransport\Repository\ProductParameter\ProductParameter\ProductParameterInterface;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Orders\Order\Repository\OrderEvent\OrderEventInterface;
use BaksDev\Orders\Order\UseCase\Admin\Edit\EditOrderDTO;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Tests\OrderNewTest;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Api\UpdateOzonOrdersPackageRequest;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\New\Products\NewOrderProductDTO;
use BaksDev\Ozon\Products\Api\Card\Identifier\GetOzonCardIdentifierRequest;
use BaksDev\Ozon\Type\Authorization\OzonAuthorizationToken;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Products\Product\Repository\ProductDetail\ProductDetailByUidInterface;
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
class UpdateOzonOrderPackageRequestTest extends KernelTestCase
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

        /** Получаем все продукцию в заказе */

        self::assertTrue(true);
        return;


        //        /** @var OrderEventInterface $OrderEventRepository */
        //        $OrderEventRepository = self::getContainer()->get(OrderEventInterface::class);
        //        $OrderEvent = $OrderEventRepository->find('0192a497-f845-705d-b122-cbb021a8bbfb');
        //
        //        if(false === $OrderEvent)
        //        {
        //            return;
        //        }
        //
        //        $EditOrderDTO = new EditOrderDTO();
        //        $OrderEvent->getDto($EditOrderDTO);
        //        $OrderUserDTO = $EditOrderDTO->getUsr();
        //
        //        if(!$OrderUserDTO)
        //        {
        //            return;
        //        }

        //        $EditOrderInvariableDTO = $EditOrderDTO->getInvariable();
        //        $number = $EditOrderInvariableDTO->getNumber();

        $number = 'number';


        /** @var GetOzonOrderInfoRequest $GetOzonOrderInfoRequest */
        $GetOzonOrderInfoRequest = self::getContainer()->get(GetOzonOrderInfoRequest::class);
        $GetOzonOrderInfoRequest->TokenHttpClient(self::$Authorization);

        /** @var ProductConstByArticleInterface $ProductConstByArticle */
        $ProductConstByArticle = self::getContainer()->get(ProductConstByArticleInterface::class);

        /** @var ProductParameterInterface $ProductParameterRepository */
        $ProductParameterRepository = self::getContainer()->get(ProductParameterInterface::class);

        /** @var UpdateOzonOrdersPackageRequest $UpdateOzonOrdersPackageRequest */
        $UpdateOzonOrdersPackageRequest = self::getContainer()->get(UpdateOzonOrdersPackageRequest::class);
        $UpdateOzonOrdersPackageRequest->TokenHttpClient(self::$Authorization);


        /** @var NewOzonOrderDTO $NewOzonOrderDTO */
        $NewOzonOrderDTO = $GetOzonOrderInfoRequest->find($number);


        /** @var NewOrderProductDTO $OrderProductDTO */


        /** Общее количество в заказе */


        $total = 0;

        foreach($NewOzonOrderDTO->getProduct() as $totals)
        {
            $total += $totals->getPrice()->getTotal();
        }


        /** Разбиваем заказ на машиноместа */

        $pack = $total;
        $package = null;

        $products = null;

        foreach($NewOzonOrderDTO->getProduct() as $key => $OrderProductDTO)
        {
            /** Получаем идентификатор карточки Озон */

            /* $ProductData = $ProductConstByArticle
                ->find($OrderProductDTO->getArticle());

            $ProductParameter = $ProductParameterRepository
                ->forProduct($ProductData->getProduct())
                ->forOfferConst($ProductData->getOfferConst())
                ->forVariationConst($ProductData->getVariationConst())
                ->forModificationConst($ProductData->getModificationConst())
                ->find(); */

            $package = $ProductParameter['package'] ?? 1;

            $productTotal = $OrderProductDTO->getPrice()->getTotal();

            for($i = 1; $i <= $productTotal; $i++)
            {
                if($total > $package)
                {
                    $products[]['products'][] = [
                        "product_id" => $OrderProductDTO->getSku(),
                        "quantity" => $package
                    ];
                }

                if($package >= $total)
                {
                    $products[]['products'][] = [
                        "product_id" => $OrderProductDTO->getSku(),
                        "quantity" => $total
                    ];
                }

                $total -= $package;

                if(0 >= $total)
                {
                    break;
                }
            }
        }



        $package = $UpdateOzonOrdersPackageRequest
            ->products($products)
            ->package($number);

        dd($package);

    }
}
