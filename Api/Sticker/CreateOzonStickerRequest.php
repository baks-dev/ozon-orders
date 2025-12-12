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

namespace BaksDev\Ozon\Orders\Api\Sticker;

use BaksDev\Ozon\Api\Ozon;

/**
 * Создать задание на формирование этикеток
 */
final class CreateOzonStickerRequest extends Ozon
{
    /**
     *
     * Метод для создания задания на асинхронное формирование этикеток.
     * Метод может вернуть несколько заданий: на формирование маленькой и большой этикетки.
     *
     * @see https://docs.ozon.ru/api/seller/?__rr=1&abt_att=1#operation/PostingAPI_CreateLabelBatchV2
     *
     */
    public function create(string $number): int|bool
    {
        $data['posting_number'] = [str_replace('O-', '', $number)];

        $response = $this->TokenHttpClient()
            ->request(
                'POST',
                '/v2/posting/fbs/package-label/create',
                ['json' => $data],
            );

        if($response->getStatusCode() === 429)
        {
            sleep(60);
            return false;
        }

        $content = $response->toArray(false);

        if($response->getStatusCode() !== 200)
        {
            /** Номер отправления не принадлежит компании */
            if($content['message'] === 'POSTING_NUMBERS_IS_INCORRECT_FOR_COMPANY')
            {
                return true;
            }

            /** Отсутствуют отправления (заказ отменен) */
            if($content['message'] === 'NO_POSTINGS_FOR_BATCH_DOWNLOAD')
            {
                return true;
            }

            $this->logger->warning(
                sprintf(
                    'ozon-orders: Ошибка %s при получении информации о стикере отправления на складе %s',
                    $response->getStatusCode(), $this->getWarehouse(),
                ),
                [self::class.':'.__LINE__, $data, $content],
            );

            return false;
        }

        /** Возвращаем идентификатор задания на формирование этикеток */

        $result = $content['result'];

        $tasks = current($result['tasks']);

        return $tasks['task_id'];
    }
}