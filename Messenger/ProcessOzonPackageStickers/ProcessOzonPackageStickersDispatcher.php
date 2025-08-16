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

namespace BaksDev\Ozon\Orders\Messenger\ProcessOzonPackageStickers;

use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Ozon\Orders\Api\PrintOzonStickerRequest;
use DateInterval;
use Imagick;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Метод получает стикер отправления Ozon и кеширует на сутки для печати в формате JPEG
 * prev @see GetOzonPackageStickersDispatcher
 */
#[AsMessageHandler(priority: 0)]
final readonly class ProcessOzonPackageStickersDispatcher
{
    public function __construct(
        private AppCacheInterface $Cache,
        private MessageDispatchInterface $MessageDispatch,
        private PrintOzonStickerRequest $printOzonStickerRequest,
    ) {}

    public function __invoke(ProcessOzonPackageStickersMessage $message): bool
    {
        $cache = $this->Cache->init('ozon-orders');
        $key = $message->getPostingNumber();

        $sticker = $cache->get($key, function(ItemInterface $item) use ($message): string|false {

            $item->expiresAfter(DateInterval::createFromDateString('1 second'));

            $ozonSticker = $this->printOzonStickerRequest
                ->forTokenIdentifier($message->getToken())
                ->find($message->getPostingNumber());

            /** Пробуем получить повторно */
            if(false === $ozonSticker)
            {
                $this->MessageDispatch->dispatch(
                    message: $message,
                    stamps: [new MessageDelay('5 seconds')],
                    transport: 'ozon-orders',
                );

                return false;
            }

            $item->expiresAfter(DateInterval::createFromDateString('1 day'));

            $imagick = new Imagick();
            $imagick->setResolution(50, 50); // DPI

            /** Одна страница, если передан один номер отправления */
            $imagick->readImageBlob($ozonSticker.'[0]'); // [0] — первая страница

            $imagick->setImageFormat('jpeg');

            $stickerJpeg = $imagick->getImageBlob();

            $imagick->clear();

            return $stickerJpeg;
        });

        return $sticker !== false;
    }
}