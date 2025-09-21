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

namespace BaksDev\Ozon\Orders\Messenger\TaskOzonPackageStickers\Create;


use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Ozon\Orders\Api\Sticker\CreateOzonStickerRequest;
use BaksDev\Ozon\Orders\Messenger\TaskOzonPackageStickers\TaskOzonPackageStickersMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Создаем задание на формирование стикера отправления
 * вызывается не раньше чем через 10 сек после упаковки
 */
#[AsMessageHandler(priority: 0)]
final readonly class CreateTaskOzonStickersDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private CreateOzonStickerRequest $CreateOzonStickerRequest,
        private MessageDispatchInterface $MessageDispatch,
    ) {}

    public function __invoke(CreateTaskOzonStickersMessage $message): void
    {

        $task = $this->CreateOzonStickerRequest
            ->forTokenIdentifier($message->getToken())
            ->create($message->getNumber());

        if(false === $task)
        {
            /** Пробуем повторить попытку */

            $this->MessageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('5 seconds')],
                transport: 'ozon-orders',
            );

            $this->logger->critical(
                sprintf('ozon-orders: Ошибка при получении задания на формирование стикера маркировки заказа %s', $message->getNumber()),
            );

            return;
        }

        $this->logger->info(
            sprintf('Получили идентификатор задания %s на формирование стикера маркировки заказа %s', $task, $message->getNumber()),
            [self::class.':'.__LINE__],
        );


        /**
         * Пробуем получить результат задания для стикера маркировки заказа
         */

        $TaskOzonPackageStickersMessage = new TaskOzonPackageStickersMessage(
            token: $message->getToken(),
            number: $message->getNumber(),
            task: $task,
        );

        $this->MessageDispatch->dispatch(
            message: $TaskOzonPackageStickersMessage,
            stamps: [new MessageDelay('30 seconds')],
            transport: 'ozon-orders',
        );
    }
}
