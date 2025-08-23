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

namespace BaksDev\Ozon\Orders\Api;

use BaksDev\Ozon\Api\Ozon;

final class UpdateOzonOrdersAwaitingDeliveryRequest extends Ozon
{

    /**
     * Передать отправление к отгрузке
     *
     * @see https://docs.ozon.ru/api/seller/#operation/PostingAPI_MoveFbsPostingToAwaitingDelivery
     *
     */
    public function package(int|string $order): array|bool
    {
        /** Если в тестовом окружении */
        if(false === $this->isExecuteEnvironment())
        {
            return true;
        }

        $order = str_replace('O-', '', (string) $order);

        $data["posting_number"] = [$order];

        $response = $this->TokenHttpClient()
            ->request(
                'POST',
                '/v2/posting/fbs/awaiting-delivery',
                ['json' => $data],
            );

        $content = $response->toArray(false);

        if($response->getStatusCode() !== 200)
        {
            /** Если упаковка уже отправлена */
            if($content['message'] === 'POSTING_ALREADY_SHIPPED')
            {
                return true;
            }

            /** Заказ отменен */
            if($content['message'] === 'POSTING_ALREADY_CANCELLED')
            {
                return true;
            }

            $this->logger->critical(
                message: sprintf('ozon-orders: Ошибка при упаковке заказа %s', $order),
                context: [self::class.':'.__LINE__, $data, $content],
            );

            return false;
        }

        return true === isset($content['result']) ? $content['result'] : false;
    }
}