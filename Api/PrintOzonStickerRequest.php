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

/**
 * Напечатать этикетку
 */
final class PrintOzonStickerRequest extends Ozon
{
    /**
     * Генерирует PDF-файл с этикетками для указанных отправлений.
     * В одном запросе можно передать не больше 20 идентификаторов. Если хотя бы для одного отправления возникнет ошибка, этикетки не будут подготовлены для всех отправлений в запросе.
     *
     * Рекомендуем запрашивать этикетки через 45–60 секунд после сборки заказа.
     *
     * Ошибка The next postings aren't ready означает, что этикетки ещё не готовы, повторите запрос позднее.
     *
     * @see https://docs.ozon.ru/api/seller/?__rr=1&abt_att=1#operation/PostingAPI_PostingFBSPackageLabel
     *
     */
    public function find(string $number): string|false
    {
        $number = str_replace('O-', '', $number);

        $data['posting_number'] = [$number];

        $response = $this->TokenHttpClient()
            ->request(
                'POST',
                '/v2/posting/fbs/package-label',
                ['json' => $data],
            );

        $content = $response->getContent(false);

        $this->logger->critical(sprintf('Стикер %s', $number), $response->toArray(false));

        if($response->getStatusCode() !== 200)
        {
            $this->logger->warning(
                sprintf(
                    'ozon-orders: Ошибка %s при получении информации о стикере отправления на складе %s',
                    $response->getStatusCode(), $this->getWarehouse(),
                ),
                [self::class.':'.__LINE__, $data, $response->toArray(false)],
            );

            return false;
        }

        return $content;
    }
}