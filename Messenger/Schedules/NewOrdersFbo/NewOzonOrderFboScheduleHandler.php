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

namespace BaksDev\Ozon\Orders\Messenger\Schedules\NewOrdersFbo;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Core\Type\Field\InputField;
use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Delivery\Repository\CurrentDeliveryEvent\CurrentDeliveryEventInterface;
use BaksDev\Delivery\Type\Id\DeliveryUid;
use BaksDev\Field\Pack\Contact\Type\ContactField;
use BaksDev\Field\Pack\Phone\Type\PhoneField;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Ozon\Orders\Api\Fbo\GetOzonOrdersFboByStatusRequest;
use BaksDev\Ozon\Orders\Api\GetOzonOrderInfoRequest;
use BaksDev\Ozon\Orders\Schedule\NewOrders\NewOrdersSchedule;
use BaksDev\Ozon\Orders\UseCase\Fbo\DeliveredOzonOrderFboDTO;
use BaksDev\Ozon\Orders\UseCase\Fbo\DeliveredOzonOrderFboHandler;
use BaksDev\Ozon\Orders\UseCase\Fbo\Products\DeliveredOzonOrderFboProductDTO;
use BaksDev\Ozon\Repository\OzonTokensByProfile\OzonTokensByProfileInterface;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\CurrentProductByBarcodeResult;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Users\Address\Services\GeocodeAddressParser;
use BaksDev\Users\Address\Type\AddressField\AddressField;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormDTO;
use BaksDev\Users\Profile\UserProfile\Repository\FieldValueForm\FieldValueFormInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileGps\UserProfileGpsInterface;
use BaksDev\Users\User\Entity\User;
use BaksDev\Users\User\Type\Id\UserUid;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Получает список НОВЫХ сборочных заданий для создания НОВЫХ заказов (на каждый Ozon токен)
 */
#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 0)]
final readonly class NewOzonOrderFboScheduleHandler
{
    public function __construct(

        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private DeduplicatorInterface $Deduplicator,
        //private GeocodeAddressParser $GeocodeAddressParser,
        ///private GetOzonOrdersByStatusRequest $getOzonOrdersNewRequest,
        private GetOzonOrdersFboByStatusRequest $GetOzonOrdersFboByStatusRequest,


        private UserProfileGpsInterface $UserProfileGpsInterfaceRepository,
        private ProductConstByArticleInterface $ProductConstByArticleRepository,
        private CurrentDeliveryEventInterface $CurrentDeliveryEventRepository,
        private UserByUserProfileInterface $UserByUserProfileRepository,
        private OzonTokensByProfileInterface $OzonTokensByProfileRepository,

        private DeliveredOzonOrderFboHandler $DeliveredOzonOrderFboHandler,
        private FieldByDeliveryChoiceInterface $FieldByDeliveryChoice,
        private GetOzonOrderInfoRequest $GetOzonOrderInfoRequest,
        private FieldValueFormInterface $fieldValue,
        private MessageDispatchInterface $MessageDispatch,
        #[Autowire(env: 'PROJECT_USER')] private string|null $projectUser = null,
    ) {}

    public function __invoke(NewOzonOrdersFboScheduleMessage $message): void
    {
        if(empty($this->projectUser))
        {
            return;
        }

        /**
         * Ограничиваем периодичность запросов
         */

        $DeduplicatorExec = $this->Deduplicator
            ->namespace('ozon-orders')
            ->expiresAfter(NewOrdersSchedule::INTERVAL)
            ->deduplication([
                (string) $message->getProfile(),
                self::class,
            ]);

        if($DeduplicatorExec->isExecuted())
        {
            return;
        }

        /* @see строку :194 */
        $DeduplicatorExec->save();


        /** Получаем все токены профиля */
        $tokensByProfile = $this->OzonTokensByProfileRepository
            ->forProfile($message->getProfile())
            ->findAll();

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            return;
        }

        foreach($tokensByProfile as $OzonTokenUid)
        {
            $this->logger->info(
                sprintf('%s: Получаем список НОВЫХ сборочных заданий', $OzonTokenUid),
                [self::class.':'.__LINE__],
            );

            /**
             * Получаем список НОВЫХ сборочных заданий токена
             */

            $orders = $this->GetOzonOrdersFboByStatusRequest
                ->forTokenIdentifier($OzonTokenUid)
                ->interval($message->getInterval())
                ->findAllDelivered();

            if($orders->valid() === false)
            {
                continue;
            }

            /**
             * Добавляем новые заказы
             *
             * @var DeliveredOzonOrderFboDTO $DeliveredOzonOrderFboDTO
             */

            foreach($orders as $DeliveredOzonOrderFboDTO)
            {
                /** Идентификатор заказа (для дедубликатора) */

                // $number = $OzonMarketOrderDTO->getOrderNumber();
                $number = $DeliveredOzonOrderFboDTO->getPostingNumber();

                $Deduplicator = $this->Deduplicator
                    ->namespace('ozon-orders')
                    ->expiresAfter('1 week')
                    ->deduplication([$number, self::class]);

                if($Deduplicator->isExecuted())
                {
                    continue;
                }

                /** Присваиваем идентификатор проекта в качестве исполнителя  */
                $NewOrderInvariable = $DeliveredOzonOrderFboDTO->getInvariable();
                $NewOrderInvariable->setUsr(new UserUid($this->projectUser));

                $OrderUserDTO = $DeliveredOzonOrderFboDTO->getUsr();
                $OrderDeliveryDTO = $OrderUserDTO->getDelivery();
                $DeliveryUid = $OrderDeliveryDTO->getDelivery();

                if(false === ($DeliveryUid instanceof DeliveryUid))
                {
                    $this->logger->critical(
                        'ozon-orders: Невозможно определить тип доставки DeliveryUid',
                        [self::class.':'.__LINE__],
                    );

                    continue;
                }


                /**
                 * Присваиваем активное событие доставки
                 */

                $DeliveryEventUid = $this->CurrentDeliveryEventRepository
                    ->forDelivery($OrderDeliveryDTO->getDelivery())
                    ->getId();

                $OrderDeliveryDTO->setEvent($DeliveryEventUid);


                /**
                 * Получаем события продукции
                 *
                 * @var DeliveredOzonOrderFboProductDTO $product
                 */
                foreach($DeliveredOzonOrderFboDTO->getProduct() as $product)
                {
                    $ProductData = $this->ProductConstByArticleRepository->find($product->getArticle());

                    if(false === ($ProductData instanceof CurrentProductByBarcodeResult))
                    {
                        $DeduplicatorExec->delete();

                        $error = sprintf('Артикул товара %s не найден', $product->getArticle());
                        throw new InvalidArgumentException($error);
                    }

                    $product
                        ->setProduct($ProductData->getEvent())
                        ->setOffer($ProductData->getOffer())
                        ->setVariation($ProductData->getVariation())
                        ->setModification($ProductData->getModification());
                }

                $Order = $this->DeliveredOzonOrderFboHandler->handle($DeliveredOzonOrderFboDTO);

                if(false === ($Order instanceof Order))
                {
                    $this->logger->critical(
                        sprintf('ozon-orders: Ошибка %s при добавлении нового заказа %s', $Order,
                            $DeliveredOzonOrderFboDTO->getPostingNumber()),
                        [$message, self::class.':'.__LINE__],
                    );

                    continue;
                }

                $this->logger->info(
                    sprintf('Добавили новый заказ %s', $DeliveredOzonOrderFboDTO->getPostingNumber()),
                    [$message, self::class.':'.__LINE__],
                );

                $Deduplicator->save();
            }
        }

        $DeduplicatorExec->delete();

    }
}
