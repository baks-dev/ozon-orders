<?php
/*
 *  Copyright 2025.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Ozon\Orders\Api\Accrual\Tests;

use BaksDev\Core\Type\Field\InputField;
use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Delivery\Repository\CurrentDeliveryEvent\CurrentDeliveryEventInterface;
use BaksDev\Finances\Entity\Finances;
use BaksDev\Finances\UseCase\NewEdit\NewEditFinancesDTO;
use BaksDev\Finances\UseCase\NewEdit\NewEditFinancesHandler;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Orders\Order\UseCase\Admin\Edit\Tests\OrderNewTest;
use BaksDev\Ozon\Orders\Api\Accrual\GetOzonOrderAccrualDayRequest;
use BaksDev\Ozon\Orders\Api\Accrual\OzonOrderAccrualDayResponse;
use BaksDev\Ozon\Orders\Type\ProfileType\TypeProfileFbsOzon;
use BaksDev\Ozon\Type\Authorization\OzonAuthorizationToken;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Products\Stocks\UseCase\Admin\Package\Tests\PackageProductStockTest;
use BaksDev\Reference\Money\Type\Money;
use BaksDev\Users\Address\Services\GeocodeAddressParser;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileGps\UserProfileGpsInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\Tests\UserNewUserProfileHandleTest;
use BaksDev\Users\User\Type\Id\UserUid;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

#[Group('ozon-orders')]
#[When(env: 'test')]
class GetOzonOrderAccrualDayRequestTest extends KernelTestCase
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

        /** @var GetOzonOrderAccrualDayRequest $GetOzonOrderAccrualDayRequest */
        $GetOzonOrderAccrualDayRequest = self::getContainer()->get(GetOzonOrderAccrualDayRequest::class);
        $GetOzonOrderAccrualDayRequest->TokenHttpClient(self::$Authorization);

        $finances = $GetOzonOrderAccrualDayRequest
            // по умолчанию - вчерашний день
            //->setDate(new \DateTimeImmutable('now'))
            ->findAll();

        if(false === $finances || false === ($finances->valid()))
        {
            return;
        }

        foreach($finances as $OzonOrderAccrualDayResponse)
        {

            // Вызываем все геттеры
            $reflectionClass = new ReflectionClass(OzonOrderAccrualDayResponse::class);
            $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach($methods as $method)
            {
                // Методы без аргументов
                if($method->getNumberOfParameters() === 0)
                {
                    // Вызываем метод
                    $data = $method->invoke($OzonOrderAccrualDayResponse);
                    // dump($data);
                }
            }

            break;
        }
    }
}
