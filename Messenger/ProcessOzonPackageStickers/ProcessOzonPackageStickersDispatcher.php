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

namespace BaksDev\Ozon\Orders\Messenger\ProcessOzonPackageStickers;

use BaksDev\Barcode\Reader\BarcodeRead;
use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Ozon\Orders\Api\Sticker\PrintOzonStickerRequest;
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
        private PrintOzonStickerRequest $printOzonStickerRequest,
        private AppCacheInterface $Cache,
        private BarcodeRead $BarcodeRead,
    ) {}

    public function __invoke(ProcessOzonPackageStickersMessage $message): bool
    {
        $ozonSticker = $this->printOzonStickerRequest
            ->forTokenIdentifier($message->getToken())
            ->find($message->getPostingNumber());

        /** Делаем проверку, что стикер читается */

        if(false === empty($ozonSticker))
        {
            /** Делаем проверку, что стикер читается */
            $isErrorRead = $this->BarcodeRead->decode($ozonSticker, decode: true)->isError();

            /** Если стикер не читается - удаляем кеш для повторной попытки */
            if(true === $isErrorRead)
            {
                $number = str_replace('O-', '', $message->getPostingNumber());
                $cache = $this->Cache->init('order-sticker');
                $cache->deleteItem($number);

                return false;
            }
        }

        return false === empty($ozonSticker);
    }
}