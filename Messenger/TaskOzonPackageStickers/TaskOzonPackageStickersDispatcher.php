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

namespace BaksDev\Ozon\Orders\Messenger\TaskOzonPackageStickers;


use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Ozon\Orders\Api\Sticker\GetOzonStickerTaskRequest;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Проверка задания на формирование этикетки и кеширует на сутки для печати в формате JPEG */
#[AsMessageHandler(priority: 0)]
final readonly class TaskOzonPackageStickersDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private GetOzonStickerTaskRequest $GetOzonStickerTaskRequest,
        private MessageDispatchInterface $MessageDispatch,
    ) {}

    public function __invoke(TaskOzonPackageStickersMessage $message): void
    {
        $result = $this->GetOzonStickerTaskRequest
            ->forTokenIdentifier($message->getToken())
            ->number($message->getNumber())
            ->get($message->getTask());

        if(false === $result)
        {
            $this->logger->warning(sprintf('%s: Ошибка при получении стикера маркировки заказа', $message->getNumber()));

            /** Пробуем получить через 5 секунд */
            $this->MessageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('3 seconds')],
                transport: 'ozon-orders',
            );

            return;
        }

        $this->logger->info(sprintf('%s: получили стикер маркировки заказа', $message->getNumber()));
    }
}
