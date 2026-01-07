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

namespace BaksDev\Ozon\Orders\Messenger\OrderSticker;


use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Messenger\Sticker\OrderStickerMessage;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Ozon\Orders\Api\Sticker\PrintOzonStickerRequest;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use Imagick;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Получаем стикеры Ozon
 */
#[AsMessageHandler(priority: 0)]
final readonly class OzonOrderStickerDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $Logger,
        private CurrentOrderEventInterface $CurrentOrderEventRepository,
        private PrintOzonStickerRequest $PrintOzonStickerRequest,
        private AppCacheInterface $Cache,
        private DeduplicatorInterface $Deduplicator,
    ) {}

    public function __invoke(OrderStickerMessage $message): void
    {
        /** Дедубликатор по идентификатору заказа */
        $Deduplicator = $this->Deduplicator
            ->namespace('orders-order')
            ->deduplication([
                (string) $message->getId(),
                self::class,
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        /**
         * Получаем информацию о заказе
         */

        $OrderEvent = $this->CurrentOrderEventRepository
            ->forOrder($message->getId())
            ->find();

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->Logger->critical(
                message: 'ozon-orders: Не найдено событие OrderEvent',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        if(false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsOzon::TYPE))
        {
            $Deduplicator->save();
            return;
        }

        /**
         * Получаем стикеры Ozon
         */

        $Deduplicator = $this->Deduplicator
            ->namespace('order-sticker')
            ->expiresAfter('1 minute');

        $cache = $this->Cache->init('order-sticker');


        /**
         * @todo переделать на логику маркировки заказа на 1 заказ - 1 стикер
         */
        foreach($OrderEvent->getProduct() as $product)
        {
            /** Если список идентификаторов отправлений пустой - пробуем определить по номеру заказа  */
            if($product->getOrderPostings()->isEmpty())
            {
                $key = $OrderEvent->getOrderNumber();
                $ozonSticker = $cache->getItem($key)->get();

                if(false === empty($ozonSticker))
                {
                    $message->addResult(number: $key, code: base64_encode($ozonSticker));
                }

                /**
                 * Если стикер по заданию не найден - пробуем распечатать
                 */

                if($Deduplicator->isExecuted())
                {
                    return;
                }

                $this->print(
                    new OzonTokenUid($OrderEvent->getOrderTokenIdentifier()),
                    $key, $message,
                );

                $Deduplicator
                    ->deduplication([self::class, $key])
                    ->save();

                return;
            }

            /**
             * @note Если стикер не получен - пробуем через время
             * @deprecated Логика разделения заказа переделывается на разделение заказов на 1 машиноместо
             * TODO: удалить (маркировки заказа на 1 заказ - 1 стикер)
             */
            foreach($product->getOrderPostings() as $orderPosting)
            {
                $key = $orderPosting->getPostingNumber();
                $ozonSticker = $cache->getItem($key)->get();

                if(false === empty($ozonSticker))
                {
                    $message->addResult(number: $key, code: base64_encode($ozonSticker));

                    continue;
                }

                /**
                 * Если стикер по заданию не найден - пробуем распечатать
                 */

                if($Deduplicator->isExecuted())
                {
                    return;
                }

                $this->print(
                    new OzonTokenUid($OrderEvent->getOrderTokenIdentifier()),
                    $key, $message,
                );

                $Deduplicator
                    ->deduplication([self::class, $key])
                    ->save();
            }
        }
    }

    /**
     * Если стикер по заданию не найден - пробуем распечатать
     */
    private function print(OzonTokenUid $token, string $number, OrderStickerMessage $message): void
    {
        $OzonSticker = $this->PrintOzonStickerRequest
            ->forTokenIdentifier($token)
            ->find($number);

        if($OzonSticker)
        {
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 3600);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, (1024 * 1024 * 256));

            $imagick = new Imagick();
            $imagick->setResolution(400, 400); // DPI

            /** Одна страница, если передан один номер отправления */
            $imagick->readImageBlob($OzonSticker.'[0]'); // [0] — первая страница

            $imagick->setImageFormat('png');
            $imageBlob = $imagick->getImageBlob();

            $imagick->clear();

            $message->addResult(number: $number, code: base64_encode($imageBlob));
        }
    }
}
