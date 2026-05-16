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

namespace BaksDev\Ozon\Orders\Messenger\OrderProductSign;


use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Ozon\Orders\Api\Exemplar\UpdateOzonOrdersExemplarRequest;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Products\Sign\Entity\Event\ProductSignEvent;
use BaksDev\Products\Sign\Messenger\ProductSignMessage;
use BaksDev\Products\Sign\Repository\CurrentEvent\ProductSignCurrentEventInterface;
use BaksDev\Products\Sign\Type\Status\ProductSignStatus\ProductSignStatusProcess;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 0)]
final class SubmitOrderProductSignDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $Logger,
        private DeduplicatorInterface $deduplicator,
        private readonly ProductSignCurrentEventInterface $ProductSignCurrentEventRepository,
        private readonly CurrentOrderEventInterface $CurrentOrderEventRepository,
        private readonly UpdateOzonOrdersExemplarRequest $UpdateOzonOrdersExemplarRequest,
        private readonly GetOzonOrderInfoRequest $GetOzonOrderInfoRequest,
    ) {}

    public function __invoke(ProductSignMessage $message): void
    {
        /** Дедубликатор по идентификатору честного знака */
        $Deduplicator = $this->deduplicator
            ->namespace('ozon-orders')
            ->deduplication([
                (string) $message->getId(),
                self::class,
            ]);

        if($Deduplicator->isExecuted() === true)
        {
            return;
        }

        /** Получаем текущее состояние честного знака */

        $ProductSignEvent = $this->ProductSignCurrentEventRepository
            ->forProductSign($message->getId())
            ->find();

        if(false === ($ProductSignEvent instanceof ProductSignEvent))
        {
            $this->Logger->critical(
                message: 'ozon-orders: Не найдено событие ProductSignEvent',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );
            return;
        }

        if(false === $ProductSignEvent->isStatusEquals(ProductSignStatusProcess::class))
        {
            return;
        }

        if(false === ($ProductSignEvent->getOrderId() instanceof OrderUid))
        {
            return;
        }

        /** Получаем текущее состояние заказа */

        $OrderEvent = $this->CurrentOrderEventRepository
            ->forOrder($ProductSignEvent->getOrderId())
            ->find();

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->Logger->critical(
                message: 'ozon-orders: Не найдено событие OrderEvent',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        /**
         * Если тип доставки заказа НЕ Ozon Fbs «Доставка службой Ozon» - Завершаем обработчик
         */
        if(false === $OrderEvent->isDeliveryTypeEquals(TypeDeliveryFbsOzon::TYPE))
        {
            $Deduplicator->save();
            return;
        }

        $OzonTokenUid = new OzonTokenUid($OrderEvent->getOrderTokenIdentifier());


        /** Получаем информацию о заказе в селлере  */

        $NewOzonOrderDTO = $this->GetOzonOrderInfoRequest
            ->forTokenIdentifier($OzonTokenUid)
            ->find($OrderEvent->getPostingNumber());

        foreach($NewOzonOrderDTO->getProduct() as $NewOzonOrderProductDTO)
        {
            /** Пропускаем если в заказе отсутствует информация о грузоместе */
            if(true === empty($NewOzonOrderProductDTO->getExemplar()))
            {
                continue;
            }

            /** Преобразуем код маркировки к требуемому формату */
            $subChar = "";
            preg_match_all('/\((\d{2})\)((?:(?!\(\d{2}\)).)*)/', $ProductSignEvent->getCode(), $matches, PREG_SET_ORDER);
            $code = $matches[0][1].$matches[0][2].$matches[1][1].$matches[1][2].$subChar.$matches[2][1].$matches[2][2].$subChar.$matches[3][1].$matches[3][2];

            /** Обновляем информацию о честном знаке */
            $this->UpdateOzonOrdersExemplarRequest
                ->forTokenIdentifier($OzonTokenUid)
                ->posting($NewOzonOrderDTO->getPostingNumber())
                ->product($NewOzonOrderProductDTO->getSku())
                ->exemplar($NewOzonOrderProductDTO->getExemplar())
                ->sign($code)
                ->update();
        }

        $Deduplicator->save();
    }
}
