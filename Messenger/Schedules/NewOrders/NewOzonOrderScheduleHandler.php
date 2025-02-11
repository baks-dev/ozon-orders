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

namespace BaksDev\Ozon\Orders\Messenger\Schedules\NewOrders;

use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Type\Gps\GpsLatitude;
use BaksDev\Core\Type\Gps\GpsLongitude;
use BaksDev\Delivery\Repository\CurrentDeliveryEvent\CurrentDeliveryEventInterface;
use BaksDev\Delivery\Type\Id\DeliveryUid;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Repository\FieldByDeliveryChoice\FieldByDeliveryChoiceInterface;
use BaksDev\Ozon\Orders\Api\GetOzonOrdersByStatusRequest;
use BaksDev\Ozon\Orders\Type\DeliveryType\TypeDeliveryFbsOzon;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderHandler;
use BaksDev\Ozon\Orders\UseCase\New\Products\NewOrderProductDTO;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\CurrentProductDTO;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Users\Address\Services\GeocodeAddressParser;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\Profile\UserProfile\Repository\UserProfileGps\UserProfileGpsInterface;
use BaksDev\Users\User\Entity\User;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NewOzonOrderScheduleHandler
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private GetOzonOrdersByStatusRequest $getOzonOrdersNewRequest,
        private UserProfileGpsInterface $UserProfileGpsInterface,
        private GeocodeAddressParser $GeocodeAddressParser,
        private ProductConstByArticleInterface $ProductConstByArticle,
        private CurrentDeliveryEventInterface $CurrentDeliveryEvent,
        private NewOzonOrderHandler $NewOzonOrderHandler,
        private DeduplicatorInterface $deduplicator,
        private UserByUserProfileInterface $UserByUserProfile,
        private FieldByDeliveryChoiceInterface $FieldByDeliveryChoice,
    )
    {
        $this->deduplicator->namespace('ozon-orders');
    }

    public function __invoke(NewOzonOrdersScheduleMessage $message): void
    {

        /**
         * Ограничиваем периодичность запросов
         */

        $DeduplicatorExec = $this->deduplicator
            ->expiresAfter('1 minute')
            ->deduplication([$message->getProfile(), self::class]);

        if($DeduplicatorExec->isExecuted())
        {
            return;
        }

        $DeduplicatorExec->save();

        /**
         * Получаем список НОВЫХ сборочных заданий
         */

        $orders = $this->getOzonOrdersNewRequest
            ->profile($message->getProfile())
            ->interval($message->getInterval())
            ->findAllNews();

        if($orders->valid() === false)
        {
            return;
        }


        $orders = iterator_to_array($orders);

        /**
         * Добавляем новые заказы
         * @var NewOzonOrderDTO $OzonMarketOrderDTO
         */

        $Deduplicator = null;

        foreach($orders as $order)
        {
            $posting = explode('-', $order->getNumber());
            array_pop($posting);
            $number = implode("-", $posting);

                $Deduplicator[$number] ?? $Deduplicator[$number] = $this->deduplicator->deduplication([$number, self::class]);
        }

        if(is_null($Deduplicator))
        {
            return;
        }

        foreach($orders as $OzonMarketOrderDTO)
        {

            $posting = explode('-', $OzonMarketOrderDTO->getNumber());
            array_pop($posting);
            $number = implode("-", $posting);

            /**
             * Добавляем в дедубликатор заказ без его связанных отправлений
             * может произойти ситуация, когда заказ во время деления на отправления короткое время находится в статусе НОВЫЙ
             */

                $Deduplicator[$number] ?? $Deduplicator[$number] = $this->deduplicator->deduplication([$number, self::class]);

            // Если передан интервал - не проверяем дедубликатор
            if($Deduplicator[$number]->isExecuted())
            {
                continue;
            }

            $NewOrderInvariable = $OzonMarketOrderDTO->getInvariable();
            $UserProfileUid = $NewOrderInvariable->getProfile();

            /**
             * Присваиваем идентификатор пользователя @UserUid по идентификатору профиля @UserProfileUid
             */
            $User = $this->UserByUserProfile->forProfile($UserProfileUid)->find();

            if(false === ($User instanceof User))
            {
                $this->logger->critical(sprintf(
                    'ozon-orders: Пользователь профиля %s для заказа %s не найден',
                    $NewOrderInvariable->getProfile(),
                    $NewOrderInvariable->getNumber()
                ), [self::class.':'.__LINE__]);

                continue;
            }

            $NewOrderInvariable->setUsr($User->getId());

            $OrderUserDTO = $OzonMarketOrderDTO->getUsr();

            $OrderDeliveryDTO = $OrderUserDTO->getDelivery();
            $DeliveryUid = $OrderDeliveryDTO->getDelivery();

            if(false === ($DeliveryUid instanceof DeliveryUid))
            {
                $this->logger->critical(
                    'ozon-orders: Невозможно определить тип доставки  DeliveryUid',
                    [self::class.':'.__LINE__]
                );
                continue;
            }

            /**
             * Если заказ DBS - адрес присваивается из запроса
             * Если заказ FBS - адрес доставки сам склад, получить и присвоить
             */

            // если доставка FBS - Доставка Ozon, геолокацию присваиваем адреса самого профиля
            if(true === $DeliveryUid->equals(TypeDeliveryFbsOzon::TYPE))
            {
                $address = $this->UserProfileGpsInterface->findUserProfileGps($UserProfileUid);

                if(empty($address['location']))
                {
                    $this->logger->critical(sprintf(
                        'ozon-orders: В профиле пользователя %s не указан адрес локации',
                        $NewOrderInvariable->getProfile()
                    ), [self::class.':'.__LINE__]);

                    continue;
                }

                $OrderDeliveryDTO->setAddress($address['location']);

                !isset($address['latitude']) ?: $OrderDeliveryDTO->setLatitude(new GpsLatitude($address['latitude']));
                !isset($address['longitude']) ?: $OrderDeliveryDTO->setLongitude(new GpsLongitude($address['longitude']));
            }
            else
            {
                // ВРЕМЕННО ПРОПУСКАЕМ ВСЕ ЗАКЗЫ КРОМЕ FBS
                continue;
            }

            /** Определяем геолокацию, если не указана */
            if(is_null($OrderDeliveryDTO->getLatitude()) || is_null($OrderDeliveryDTO->getLongitude()))
            {
                $GeocodeAddressDTO = $this->GeocodeAddressParser->getGeocode($OrderDeliveryDTO->getAddress());

                $OrderDeliveryDTO->setAddress($GeocodeAddressDTO->getAddress());
                $OrderDeliveryDTO->setLatitude($GeocodeAddressDTO->getLatitude());
                $OrderDeliveryDTO->setLongitude($GeocodeAddressDTO->getLongitude());
            }


            /**
             * Присваиваем активное событие доставки
             */

            $DeliveryEvent = $this->CurrentDeliveryEvent->get($OrderDeliveryDTO->getDelivery());
            $OrderDeliveryDTO->setEvent($DeliveryEvent?->getId());


            //            /**
            //             * Если доставка собственной службой - присваиваем адрес пользователя
            //             * Определяем свойства доставки и присваиваем адрес
            //             */
            //
            //            $fields = $this->FieldByDeliveryChoice->fetchDeliveryFields($OrderDeliveryDTO->getDelivery());
            //
            //            $address_field = array_filter($fields, function ($v) {
            //                /** @var InputField $InputField */
            //                return $v->getType()->getType() === 'address_field';
            //            });
            //
            //            $address_field = current($address_field);
            //
            //            if($address_field !== false)
            //            {
            //                $OrderDeliveryFieldDTO = new OrderDeliveryFieldDTO();
            //                $OrderDeliveryFieldDTO->setField($address_field);
            //                $OrderDeliveryFieldDTO->setValue($OrderDeliveryDTO->getAddress());
            //                $OrderDeliveryDTO->addField($OrderDeliveryFieldDTO);
            //            }


            /**
             * Получаем события продукции
             * @var NewOrderProductDTO $product
             */
            foreach($OzonMarketOrderDTO->getProduct() as $product)
            {
                $ProductData = $this->ProductConstByArticle->find($product->getArticle());

                if(false === ($ProductData instanceof CurrentProductDTO))
                {
                    $error = sprintf('Артикул товара %s не найден', $product->getArticle());
                    throw new InvalidArgumentException($error);
                }

                $product
                    ->setProduct($ProductData->getEvent())
                    ->setOffer($ProductData->getOffer())
                    ->setVariation($ProductData->getVariation())
                    ->setModification($ProductData->getModification());
            }

            $Order = $this->NewOzonOrderHandler->handle($OzonMarketOrderDTO);

            if(false === ($Order instanceof Order))
            {
                $this->logger->critical(
                    sprintf('ozon-orders: Ошибка %s при добавлении нового заказа %s', $Order, $OzonMarketOrderDTO->getNumber()),
                    [$message, self::class.':'.__LINE__]
                );
                continue;
            }

            $this->logger->info(
                sprintf('Добавили новый заказ %s', $OzonMarketOrderDTO->getNumber()),
                [$message, self::class.':'.__LINE__]
            );

            $Deduplicator[$number]->save();


        }

        $DeduplicatorExec->delete();
    }
}
