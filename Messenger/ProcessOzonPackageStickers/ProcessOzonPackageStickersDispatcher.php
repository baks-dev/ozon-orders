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
use BaksDev\Ozon\Orders\Api\Sticker\PrintOzonStickerRequest;
use DateInterval;
use Imagick;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Получает стикер отправления Ozon и кеширует на сутки для печати в формате JPEG
 * prev @see GetOzonPackageStickersDispatcher
 */
#[AsMessageHandler(priority: 0)]
final readonly class ProcessOzonPackageStickersDispatcher
{
    public function __construct(
        private AppCacheInterface $Cache,
        private PrintOzonStickerRequest $printOzonStickerRequest,
    ) {}

    public function __invoke(ProcessOzonPackageStickersMessage $message): bool
    {
        /** Указываем отличающийся namespace для кеша стикера (не сбрасываем по какому-либо модулю) */
        $cache = $this->Cache->init('order-sticker');
        $key = $message->getPostingNumber();

        $sticker = $cache->get($key, function(ItemInterface $item) use ($message): string|false {

            $item->expiresAfter(DateInterval::createFromDateString('1 second'));

            $ozonSticker = $this->printOzonStickerRequest
                ->forTokenIdentifier($message->getToken())
                ->find($message->getPostingNumber());

            if(false === $ozonSticker)
            {
                return false;
            }

            $item->expiresAfter(DateInterval::createFromDateString('1 week'));

            Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 3600);
            $imagick = new Imagick();
            $imagick->setResolution(200, 200); // DPI

            /** Одна страница, если передан один номер отправления */
            $imagick->readImageBlob($ozonSticker.'[0]'); // [0] — первая страница

            $imagick->setImageFormat('png');
            $imageBlob = $imagick->getImageBlob();

            $imagick->clear();

            return $imageBlob;

        });

        return $sticker !== false;
    }
}