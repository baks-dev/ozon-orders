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

namespace BaksDev\Ozon\Orders\Api\Fbo;

use BaksDev\Ozon\Api\Ozon;
use BaksDev\Ozon\Orders\UseCase\Cancel\CancelOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\Fbo\DeliveredOzonOrderFboDTO;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Информация о заказах
 */
#[Autoconfigure(public: true, shared: false)]
final class GetOzonOrdersFboByStatusRequest extends Ozon
{
    private ?string $cursor = null;

    private ?DateTimeImmutable $fromDate = null;

    private string|false $status = false;

    private DateInterval $interval;

    public function interval(DateInterval|string|null $interval): self
    {
        if(empty($interval))
        {
            $this->interval = DateInterval::createFromDateString('1 hour');
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

    /**
     *
     * Возвращает список отправлений за указанный период времени.
     * Если период больше года, вернётся ошибка PERIOD_IS_TOO_LONG.
     *
     * Дополнительно можно отфильтровать отправления по их статусу.
     *
     * Возвращает информацию последних заказах со статусом:
     *
     * Статус отправления:
     *
     * awaiting_packaging — ожидает упаковки;
     * awaiting_deliver — ожидает отгрузки;
     * delivering — доставляется;
     * delivered — доставлено;
     * cancelled — отменено.
     *
     * @see https://docs.ozon.ru/api/seller/#operation/PostingFboList
     *
     */
    private function findAll(): Generator|bool
    {
        if(false === $this->status)
        {
            throw new InvalidArgumentException('Invalid Argument $status');
        }

        $dateTimeNow = new DateTimeImmutable();

        if(false === ($this->fromDate instanceof DateTimeImmutable))
        {
            // Новые заказы за последний час
            $this->fromDate = $dateTimeNow->sub($this->interval ?? DateInterval::createFromDateString('1 hour'));

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

        $data['sort_dir'] = 'DESC'; // сортировка по убыванию
        $data['limit'] = 100; // Количество значений в ответе
        $data['filter']['statuses'] = [$this->status]; // Статус отправления

        /**
         * Дата начала периода, за который нужно получить список отправлений.
         * Период по умолчанию - 1 неделя
         */
        $sinceDate = $dateTimeNow->sub(DateInterval::createFromDateString('1 day'));
        $data['filter']['since'] = $sinceDate->format(DateTimeInterface::W3C);
        $data['filter']['to'] = $dateTimeNow->format(DateTimeInterface::W3C);

        while(true)
        {
            $data['cursor'] = $this->cursor;

            $response = $this->TokenHttpClient()
                ->request(
                    'POST',
                    '/v3/posting/fbo/list',
                    ['json' => $data],
                );

            $content = $response->toArray(false);

            $this->status = false;

            if($response->getStatusCode() !== 200)
            {
                $this->logger->critical(
                    sprintf('ozon-orders: Ошибка при получении заказов со статусом %s', $this->status),
                    [
                        'content' => $content,
                        self::class.':'.__LINE__],
                );

                return false;
            }

            if(empty($content['postings']))
            {
                break;
            }

            $this->cursor = $content['cursor'];

            yield $content['postings'];

            if(count($content['postings']) < 100)
            {
                break;
            }

            if(empty($content['has_next']))
            {
                break;
            }
        }
    }

    /**
     * Заказы только в статусе "Доставлено"
     */
    public function findAllDelivered(): Generator|bool
    {
        $this->status = 'delivered';

        $orders = $this->findAll();


        if(false === $orders || false === $orders->valid())
        {
            return false;
        }

        foreach($orders as $all)
        {
            foreach($all as $order)
            {
                yield new DeliveredOzonOrderFboDTO($order, $this->getIdentifier());
            }
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
