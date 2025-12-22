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

namespace BaksDev\Ozon\Orders\Messenger;


use BaksDev\Barcode\Writer\BarcodeFormat;
use BaksDev\Barcode\Writer\BarcodeType;
use BaksDev\Barcode\Writer\BarcodeWrite;
use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Orders\Order\Messenger\Sticker\OrderStickerMessage;
use BaksDev\Products\Sign\Repository\ProductSignByOrder\ProductSignByOrderInterface;
use BaksDev\Products\Stocks\Messenger\Part\ProductStockPartMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Получаем стикер маркировки заказов Ozon */
#[AsMessageHandler(priority: 0)]
final readonly class ProductStockPartDispatcher
{
    public function __construct(
        #[Target('productsSignLogger')] private LoggerInterface $logger,
        private AppCacheInterface $Cache,
    ) {}

    public function __invoke(ProductStockPartMessage $message): void
    {
        $cache = $this->Cache->init('order-sticker');
        $sticker = null;

        /** Получаем стикеры маркировки заказа на продукцию */
        foreach($message->getOrders() as $order)
        {
            $number = str_replace('O-', '', (string) $order->number);

            $ozonSticker = $cache->getItem($number)->get();

            if(false === empty($ozonSticker))
            {
                $sticker[(string) $order->id][$number]['ozon'][] = base64_encode($ozonSticker);
                continue;
            }

            $this->logger->critical(
                sprintf('ozon-orders: стикер маркировки заказа %s не найден', $order->number),
                [self::class.':'.__LINE__],
            );
        }

        $message->addSticker($sticker);
    }
}
