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

namespace BaksDev\Ozon\Orders\Api;

use BaksDev\Ozon\Api\Ozon;
use InvalidArgumentException;

/**
 * Информация о заказах
 */
final class UpdateOzonOrdersPackageRequest extends Ozon
{

    private array|false $products = false;

    public function products(array $products): self
    {
        $this->products = $products;
        return $this;
    }


    /**
     * Частичная сборка отправления
     *
     * @see https://docs.ozon.ru/api/seller/#operation/PostingAPI_ShipFbsPostingPackage
     *
     */
    public function package(int|string $order): bool
    {
        if(false === $this->isExecuteEnvironment())
        {
            return true;
        }

        if(empty($this->products))
        {
            throw new InvalidArgumentException('Invalid Argument $products');
        }

        $order = str_replace('O-', '', (string) $order);

        $data = [
            "packages" => $this->products,
            "posting_number" => $order,
        ];

        $response = $this->TokenHttpClient()
            ->request(
                'POST',
                '/v4/posting/fbs/ship',
                ['json' => $data],
            );

        $content = $response->toArray(false);

        if($response->getStatusCode() !== 200)
        {
            $this->logger->critical(
                message: sprintf('ozon-orders: Ошибка при упаковке заказа %s', $order),
                context: [self::class.':'.__LINE__, $data, $content]
            );

            return false;
        }

        return isset($content['result']);
    }
}