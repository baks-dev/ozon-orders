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

namespace BaksDev\Ozon\Orders\Messenger\ExemplarInfo;


use BaksDev\Core\Messenger\MessageDelay;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Materials\Sign\Repository\MaterialSignByOrder\MaterialSignByOrderInterface;
use BaksDev\Orders\Order\Entity\Event\OrderEvent;
use BaksDev\Orders\Order\Repository\CurrentOrderEvent\CurrentOrderEventInterface;
use BaksDev\Ozon\Orders\Api\Exemplar\GetOzonOrdersExemplarStatusRequest;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Api\Package\UpdateOzonOrdersPackageRequest;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use BaksDev\Products\Sign\Repository\ProductSignByOrder\ProductSignByOrderInterface;
use BaksDev\Products\Sign\Repository\ProductSignByOrder\ProductSignByOrderResult;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Отправляет информацию об экземплярах заказа */
#[AsMessageHandler(priority: 0)]
final class UpdateOrderExemplarInfoDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private CurrentOrderEventInterface $CurrentOrderEventRepository,
        private GetOzonOrderInfoRequest $GetOzonOrderInfoRequest,
        private UpdateOzonOrdersPackageRequest $UpdateOzonOrdersPackageRequest,
        private GetOzonOrdersExemplarStatusRequest $GetOzonOrdersExemplarStatusRequest,
        private MessageDispatchInterface $MessageDispatch,
        private ?ProductSignByOrderInterface $ProductSignByOrderRepository = null,
        private ?MaterialSignByOrderInterface $MaterialSignByOrderRepository = null,
    ) {}

    /** Отправляет информацию об экземплярах заказа */
    public function __invoke(UpdateOrderExemplarInfoMessage $message): void
    {

        $OrderEvent = $this->CurrentOrderEventRepository
            ->forOrder($message->getOrderId())
            ->find();

        if(false === ($OrderEvent instanceof OrderEvent))
        {
            $this->logger->critical(
                message: 'ozon-orders: Не найдено событие OrderEvent',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        if(false === ($OrderEvent->getOrderProfile() instanceof UserProfileUid))
        {
            $this->logger->critical(
                message: 'ozon-orders: Невозможно определить идентификатор профиля склада заказа',
                context: [self::class.':'.__LINE__, var_export($message, true)],
            );

            return;
        }

        /** Токен из заказа в системе (был установлен при получении заказа из Ozon) */
        $OzonTokenUid = new OzonTokenUid($OrderEvent->getOrderTokenIdentifier());


        /** Получаем информацию об экземплярах заказа */

        $isExemplar = $this
            ->GetOzonOrdersExemplarStatusRequest
            ->forTokenIdentifier($OzonTokenUid)
            ->find($OrderEvent->getOrderNumber());

        if(true === $isExemplar)
        {
            return;
        }

        $exemplars = $this->GetOzonOrdersExemplarStatusRequest->getExemplars();

        if(false === $isExemplar && false === $exemplars)
        {
            /** Пробуем запросить информацию об экземплярах позже */
            $this->MessageDispatch->dispatch(
                message: $message,
                stamps: [new MessageDelay('5 seconds')],
                transport: $OrderEvent->getOrderProfile().'-low',
            );

            return;
        }

        /**
         * Получаем информацию о честных знаках заказа
         */

        /** Получаем информацию о честных знаках на продукцию */
        if($this->ProductSignByOrderRepository instanceof ProductSignByOrderInterface)
        {
            $result = $this->ProductSignByOrderRepository
                ->forOrder($message->getOrderId())
                ->findAll();

            if(false === $result || false === $result->valid())
            {
                return;
            }

            foreach($exemplars['products'] as $prd => $order)
            {
                foreach($order['exemplars'] as $exm => $exemplar)
                {
                    if(true === $result->valid())
                    {
                        /** @var ProductSignByOrderResult $ProductSignByOrderResult */
                        $ProductSignByOrderResult = $result->current();
                        $result->next();
                    }

                    /** Передаем в качестве ГТД - комментарий   */
                    $exemplars['products'][$prd]['exemplars'][$exm]['gtd'] = $ProductSignByOrderResult->getComment() ?: 'Отсутствует';

                    /** Передаем в качестве маркировки - честный знак */
                    $exemplars['products'][$prd]['exemplars'][$exm]['marks'] = [
                        'mark' => $ProductSignByOrderResult->getSmallCode(),
                        'mark_type' => 'mandatory_mark',
                    ];
                }
            }
        }

        /** Получаем информацию о честных знаках на сырье */
        if($this->MaterialSignByOrderRepository instanceof MaterialSignByOrderInterface)
        {
            $this->MaterialSignByOrderRepository
                ->forOrder($message->getOrderId())
                ->findAll();
        }
    }
}
