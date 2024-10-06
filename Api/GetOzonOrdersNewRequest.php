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
use BaksDev\Ozon\Orders\UseCase\New\OzonMarketOrderDTO;
use BaksDev\Yandex\Market\Api\YandexMarket;
use BaksDev\Yandex\Market\Orders\UseCase\New\YandexMarketOrderDTO;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;

/**
 * Информация о заказах
 */
final class GetOzonOrdersNewRequest extends Ozon
{
    private int $page = 1;

    private ?DateTimeImmutable $fromDate = null;

    /**
     * Возвращает информацию последних заказах со статусом:
     *
     * awaiting_packaging - заказ находится в обработке
     *
     * @see https://docs.ozon.ru/api/seller/#operation/PostingAPI_GetFbsPostingListV3
     *
     */
    public function findAll(?DateInterval $interval = null)
    {
        $dateTime = new DateTimeImmutable();

        if(!$this->fromDate)
        {
            // Новые заказы за последние 5 минут (планировщик на каждую минуту)
            $this->fromDate = $dateTime->sub($interval ?? DateInterval::createFromDateString('5 minutes'));

            /** В 3 часа ночи получаем заказы за сутки */
            $currentHour = $dateTime->format('H');
            $currentMinute = $dateTime->format('i');

            if($currentHour === '03' && $currentMinute >= '00' && $currentMinute <= '05')
            {
                $this->fromDate = $dateTime->sub(DateInterval::createFromDateString('1 days'));
            }
        }

        $data['dir'] = 'DESC'; // сортировка
        $data['limit'] = 1000; // Количество значений в ответе

        //  фильтр по времени сборки
        $data['filter']['since'] = $this->fromDate->format(DateTimeInterface::ATOM); // Дата начала периода ('2023-11-03T11:47:39.878Z')
        $data['filter']['to'] = $dateTime->format(DateTimeInterface::ATOM);   // Дата конца периода ('2023-11-03T11:47:39.878Z')
        $data['filter']['status'] = 'awaiting_packaging'; // Статус отправления


        /*$data["with"] = [
            "analytics_data" => true, // Добавить в ответ данные аналитики.
            "barcodes" => true, // Добавить в ответ штрихкоды отправления.
            "financial_data" => true, // Добавить в ответ данные аналитики.
            "translit" => true // Выполнить транслитерацию возвращаемых значений.
        ];*/

        $response = $this->TokenHttpClient()
            ->request(
                'GET',
                '/v3/posting/fbs/list',
                ['json' => [$data]],
            );

        $content = $response->toArray(false);


        if($response->getStatusCode() !== 200)
        {
            foreach($content['errors'] as $error)
            {
                $this->logger->critical($error['code'].': '.$error['message'], [self::class.':'.__LINE__]);
            }

            return false;
        }

        foreach($content['postings'] as $order)
        {
            yield new OzonMarketOrderDTO($order, $this->getProfile());
        }
    }
}
