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
use BaksDev\Ozon\Orders\UseCase\Cancel\CancelOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;

/**
 * Информация о заказах
 */
final class GetOzonOrdersByStatusRequest extends Ozon
{
    private ?DateTimeImmutable $fromDate = null;

    private string|false $status = false;

    private DateInterval $interval;

    public function interval(DateInterval|string|null $interval): self
    {
        if(empty($interval))
        {
            $this->interval = DateInterval::createFromDateString('30 minutes');
            return $this;
        }

        if($interval instanceof DateInterval)
        {
            $this->interval = $interval;

            return $this;
        }

        $this->interval = DateInterval::createFromDateString($interval);

        return $this;
    }

    /**
     * Возвращает информацию последних заказах со статусом:
     *
     * Статус отправления:
     *
     * awaiting_registration — ожидает регистрации,
     * acceptance_in_progress — идёт приёмка,
     * awaiting_approve — ожидает подтверждения,
     * awaiting_packaging — ожидает упаковки,
     * awaiting_deliver — ожидает отгрузки,
     * arbitration — арбитраж,
     * client_arbitration — клиентский арбитраж доставки,
     * delivering — доставляется,
     * driver_pickup — у водителя,
     * delivered — доставлено,
     * cancelled — отменено,
     * not_accepted — не принят на сортировочном центре,
     * sent_by_seller – отправлено продавцом.
     *
     * @see https://docs.ozon.ru/api/seller/#operation/PostingAPI_GetFbsPostingListV3
     *
     */
    private function findAll(): array|bool
    {
        if(false === $this->status)
        {
            throw new InvalidArgumentException('Invalid Argument $status');
        }

        $dateTimeNow = new DateTimeImmutable();

        if(!$this->fromDate)
        {
            // Новые заказы за последние 30 минут (планировщик на каждую минуту)
            $this->fromDate = $dateTimeNow->sub($this->interval ?? DateInterval::createFromDateString('30 minutes'));

            /**
             * В 3 часа ночи получаем заказы за сутки
             */

            $currentHour = $dateTimeNow->format('H');
            $currentMinute = $dateTimeNow->format('i');

            if($currentHour === '03' && $currentMinute >= '00' && $currentMinute <= '05')
            {
                $this->fromDate = $dateTimeNow->sub(DateInterval::createFromDateString('1 day'));
            }
        }

        $data['dir'] = 'DESC'; // сортировка по убыванию
        $data['limit'] = 1000; // Количество значений в ответе
        $data['filter']['since'] = $this->fromDate->format(DateTimeInterface::W3C); // Дата начала периода (Y-m-d\TH:i:sP)
        $data['filter']['to'] = $dateTimeNow->format(DateTimeInterface::W3C);   // Дата конца периода (Y-m-d\TH:i:sP)
        $data['filter']['status'] = $this->status; // Статус отправления

        /** Новые заказы только согласно идентификатору склада */
        //if('awaiting_packaging' === $this->status)
        //{
        //    $data['filter']['warehouse_id'] = [$this->getWarehouse()]; // Идентификатор склада.
        //}

        $data['filter']['warehouse_id'] = [$this->getWarehouse()]; // Идентификатор склада.

        $response = $this->TokenHttpClient()
            ->request(
                'POST',
                '/v3/posting/fbs/list',
                ['json' => $data],
            );

        $content = $response->toArray(false);

        $this->status = false;

        if($response->getStatusCode() !== 200)
        {
            $this->logger->critical(
                sprintf('ozon-orders: Ошибка при получении  заказов со статусом %s', $this->status),
                [
                    'content' => $content,
                    self::class.':'.__LINE__],
            );

            return false;
        }

        return $content['result']['postings'];
    }

    /** Заказы только в статусе "отменено" */
    public function findAllCancel(): Generator|bool
    {
        $this->status = 'cancelled';

        $orders = $this->findAll();

        if(false === $orders)
        {
            return false;
        }

        foreach($orders as $order)
        {
            yield new CancelOzonOrderDTO($order);
        }
    }

    /** Заказы только в статусе "ожидает упаковки" */
    public function findAllNews(): Generator|bool
    {
        $this->status = 'awaiting_packaging';

        $orders = $this->findAll();


        if(false === $orders)
        {
            return false;
        }

        foreach($orders as $order)
        {
            yield new NewOzonOrderDTO($order, $this->getProfile(), $this->getIdentifier());
        }
    }

    /** Поиск по переданному статусу */
    public function findByStatus(string $status): Generator|bool
    {
        $this->status = $status;

        $orders = $this->findAll();

        if(false === $orders)
        {
            return false;
        }

        foreach($orders as $order)
        {
            yield new NewOzonOrderDTO($order, $this->getProfile(), $this->getIdentifier());
        }
    }
}