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

namespace BaksDev\Ozon\Orders\Api\Package;

use BaksDev\Ozon\Api\Ozon;
use InvalidArgumentException;

/**
 * Собрать заказ
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
     * Делит заказ на отправления и переводит его в статус awaiting_deliver.
     *
     * Каждый элемент в packages может содержать несколько элементов products или отправлений. Каждый элемент в
     * products — это товар, включённый в данное отправление. Чтобы разделить заказ, передайте в массиве packages
     * несколько объектов.
     *
     * @see https://docs.ozon.ru/api/seller/?__rr=1&abt_att=1#operation/PostingAPI_ShipFbsPostingV4
     *
     */
    public function package(int|string $order): UpdateOzonOrdersPackageDTO|bool
    {
        /** Если в тестовом окружении */
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
            "with" => ['additional_data' => true],
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
                context: [self::class.':'.__LINE__, $data, $content],
            );

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

            /** Заказу требуется указать Доп. информацию */
            if($content['message'] === 'EXEMPLAR_INFO_NOT_FILLED_COMPLETELY')
            {
                return true;
            }

            /** Статус заказа уже изменился */
            if($content['message'] === 'HAS_INCORRECT_STATUS')
            {
                return true;
            }

            return false;
        }

        return true === isset($content['result']) ? new UpdateOzonOrdersPackageDTO($content) : false;
    }
}