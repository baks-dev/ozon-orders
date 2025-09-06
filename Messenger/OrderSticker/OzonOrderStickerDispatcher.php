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

namespace BaksDev\Ozon\Orders\Messenger\OrderSticker;


use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Messenger\Sticker\OrderStickerMessage;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Ozon\Orders\Messenger\ProcessOzonPackageStickers\ProcessOzonPackageStickersMessage;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Получаем стикеры Ozon
 */
#[AsMessageHandler(priority: 0)]
final readonly class OzonOrderStickerDispatcher
{
    public function __construct(
        private CurrentOrderEventInterface $CurrentOrderEventRepository,
        private MessageDispatchInterface $messageDispatch,
        private AppCacheInterface $Cache,
    ) {}

    public function __invoke(OrderStickerMessage $message): void
    {
        /**
         * Получаем информацию о заказе
         */

        $OrderEvent = $this->CurrentOrderEventRepository
            ->forOrder($message->getId())
            ->find();

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            return;
        }

        if(false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsOzon::class))
        {
            return;
        }

        /**
         * Получаем стикеры Ozon
         */

        $cache = $this->Cache->init('order-sticker');

        foreach($OrderEvent->getProduct() as $product)
        {
            foreach($product->getOrderPostings() as $orderPosting)
            {
                $key = $orderPosting->getPostingNumber();
                $ozonSticker = $cache->getItem($key)->get();

                /**
                 * Если стикер не получен - пробуем получить заново
                 */
                if(empty($ozonSticker))
                {
                    $ProcessOzonPackageStickersMessage = new ProcessOzonPackageStickersMessage(
                        new OzonTokenUid($OrderEvent->getOrderTokenIdentifier()),
                        $orderPosting->getPostingNumber(),
                    );

                    /** @see ProcessOzonPackageStickersDispatcher */
                    $this->messageDispatch->dispatch(message: $ProcessOzonPackageStickersMessage);
                    $ozonSticker = $cache->getItem($key)->get();
                }

                if(false === empty($ozonSticker))
                {
                    $message->addResult(number: $key, code: base64_encode($ozonSticker));
                }
            }
        }
    }
}
