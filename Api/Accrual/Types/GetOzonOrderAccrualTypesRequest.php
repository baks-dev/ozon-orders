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

namespace BaksDev\Ozon\Orders\Api\Accrual\Types;

use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Ozon\Api\Ozon;
use BaksDev\Ozon\Products\Api\Card\Identifier\GetOzonCardNameRequest;
use BaksDev\Ozon\Products\Api\Card\Identifier\GetOzonCardOfferIdRequest;
use BaksDev\Ozon\Repository\OzonToken\OzonTokenInterface;
use BaksDev\Ozon\Repository\OzonTokensByProfile\OzonTokensByProfileInterface;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Информация о заказах
 */
#[Autoconfigure(public: true, shared: false)]
final class GetOzonOrderAccrualTypesRequest extends Ozon
{
    /**
     * Получить справочник начислений
     *
     * @see https://docs.ozon.ru/api/seller/#operation/GetFinanceAccrualTypes
     *
     */
    public function findAll()//: Generator|bool
    {

        $response = $this->TokenHttpClient()
            ->request(
                'POST',
                '/v1/finance/accrual/types',
            );

        $content = $response->toArray(false);

        if($response->getStatusCode() !== 200)
        {
            $this->logger->critical(
                sprintf(
                    'ozon-orders: Ошибка %s при получении справочника начислений',
                    $response->getStatusCode(),
                ),
                [self::class.':'.__LINE__, $content],
            );

            return false;
        }

        if(empty($content['accrual_types']))
        {
            return false;
        }

        return $content['accrual_types'];
    }
}