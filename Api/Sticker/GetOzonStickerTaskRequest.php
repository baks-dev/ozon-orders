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
use DateInterval;
use Imagick;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Получить файл с этикетками
 */
final class GetOzonStickerTaskRequest extends Ozon
{
    private string $number;

    /**Передаем идентификатор отправления для кеширования */
    public function number(string $number): self
    {
        $this->number = str_replace('O-', '', $number);
        return $this;
    }

    /**
     * Метод для получения этикеток по идентификатору задания
     *
     * @see https://docs.ozon.ru/api/seller/?__rr=1&abt_att=1#operation/PostingAPI_GetLabelBatch
     */
    public function get(int $task): bool
    {
        /**
         * Обрабатываем и сохраняем в кеш этикетку под номер отправления
         * Указываем отличающийся namespace для кеша стикера (не сбрасываем по какому-либо модулю)
         */
        $cache = $this->getCacheInit('order-sticker');
        $item = $cache->getItem($this->number);

        if($item->isHit())
        {
            return true;
        }

        $data['task_id'] = $task;

        $response = $this->TokenHttpClient()
            ->request(
                'POST',
                'v1/posting/fbs/package-label/get',
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
            $this->logger->warning(
                sprintf(
                    'ozon-orders: Ошибка %s при получении информации о стикере маркировки заказа на складе %s',
                    $response->getStatusCode(), $this->getWarehouse(),
                ),
                [self::class.':'.__LINE__, $data, $content],
            );

            return false;
        }

        /** Возвращаем идентификатор задания на формирование этикеток */

        $result = $content['result'];

        if($result['status'] === 'completed')
        {

            $cache->get($this->number, function(ItemInterface $item) use ($result): string|false {

                /** Временно кешируем этикетку на 1 секунду */
                $item->expiresAfter(DateInterval::createFromDateString('1 seconds'));

                $response = $this->TokenHttpClient()->request('GET', $result['file_url']);

                if($response->getStatusCode() !== 200)
                {
                    return false;
                }

                $ozonSticker = $response->getContent(false);

                /** Кешируем этикетку на 1 неделю */
                $item->expiresAfter(DateInterval::createFromDateString('1 week'));

                Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 3600);
                $imagick = new Imagick();
                $imagick->setResolution(300, 300); // DPI

                /** Одна страница, если передан один номер отправления */
                $imagick->readImageBlob($ozonSticker.'[0]'); // [0] — первая страница

                $imagick->setImageFormat('png');
                $imageBlob = $imagick->getImageBlob();

                $imagick->clear();

                return $imageBlob;

            });

            return true;
        }

        /** Если этикетка еще не готова - пробуем позже */

        $this->logger->critical(
            sprintf('ozon-orders: Стикер отправления %s не готов к печати', $this->number),
        );

        return false;
    }
}