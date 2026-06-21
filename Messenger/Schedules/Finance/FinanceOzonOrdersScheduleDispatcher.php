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

namespace BaksDev\Ozon\Orders\Messenger\Schedules\Finance;


use BaksDev\Core\Deduplicator\DeduplicatorInterface;
use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Finances\Entity\Event\FinancesEvent;
use BaksDev\Finances\Entity\Finances;
use BaksDev\Finances\Messenger\Default\FinancesMessage;
use BaksDev\Finances\Repository\CurrentFinancesEventByIdentifier\CurrentFinancesEventByIdentifierInterface;
use BaksDev\Finances\Repository\ExistFinance\ExistFinanceInterface;
use BaksDev\Finances\UseCase\NewEdit\NewEditFinancesDTO;
use BaksDev\Finances\UseCase\NewEdit\NewEditFinancesHandler;
use BaksDev\Orders\Order\Repository\OrderByPosting\OrderByPostingInterface;
use BaksDev\Orders\Order\Type\Id\OrderUid;
use BaksDev\Ozon\Orders\Api\Accrual\GetOzonOrderAccrualDayRequest;
use BaksDev\Ozon\Orders\Type\PaymentType\TypePaymentFbsOzon;
use BaksDev\Ozon\Repository\OzonTokensByProfile\OzonTokensByProfileInterface;
use BaksDev\Payment\Type\Id\PaymentUid;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\CurrentProductByBarcodeResult;
use BaksDev\Products\Product\Repository\CurrentProductByArticle\ProductConstByArticleInterface;
use BaksDev\Products\Product\Type\Invariable\ProductInvariableUid;
use BaksDev\Users\Profile\UserProfile\Repository\UserByUserProfile\UserByUserProfileInterface;
use BaksDev\Users\User\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[Autoconfigure(shared: false)]
#[AsMessageHandler(priority: 0)]
final class FinanceOzonOrdersScheduleDispatcher
{
    public function __construct(
        #[Target('ozonOrdersLogger')] private LoggerInterface $logger,
        private DeduplicatorInterface $Deduplicator,
        private MessageDispatchInterface $messageDispatch,
        private OzonTokensByProfileInterface $OzonTokensByProfileRepository,
        private GetOzonOrderAccrualDayRequest $GetOzonOrderAccrualDayRequest,
        private OrderByPostingInterface $OrderByPostingRepository,
        private ExistFinanceInterface $ExistFinanceRepository,
        private NewEditFinancesHandler $NewEditFinancesHandler,
        private UserByUserProfileInterface $UserByUserProfileRepository,
        private ProductConstByArticleInterface $ProductConstByArticleRepository,
        private CurrentFinancesEventByIdentifierInterface $CurrentFinancesEventByIdentifierRepository
    ) {}

    public function __invoke(FinanceOzonOrdersScheduleMessage $message): void
    {
        /**
         * Ограничиваем периодичность запросов
         */

        $DeduplicatorExec = $this->Deduplicator
            ->namespace('ozon-orders')
            ->expiresAfter('1 hour')
            ->deduplication([
                (string) $message->getProfile(),
                self::class,
            ]);

        if($DeduplicatorExec->isExecuted() && $message->isForce() === false)
        {
            return;
        }

        /** Получаем все токены профиля */
        $tokensByProfile = $this->OzonTokensByProfileRepository
            ->forProfile($message->getProfile())
            ->findAll();

        if(false === $tokensByProfile || false === $tokensByProfile->valid())
        {
            return;
        }

        /** Получаем идентификатор пользователя по профилю */

        // Получаем идентификатор пользователя по профилю
        $User = $this->UserByUserProfileRepository
            ->forProfile($message->getProfile())
            ->find();

        if(false === ($User instanceof User))
        {
            $this->logger->info(
                sprintf('ozon-orders: Пользователь по идентификатору %s профиля не найден', $message->getProfile()),
                [self::class.':'.__LINE__],
            );

            return;
        }

        foreach($tokensByProfile as $OzonTokenUid)
        {
            $this->logger->info(
                sprintf('%s: Получаем список платежей за вчерашний день', $OzonTokenUid),
                [self::class.':'.__LINE__],
            );

            /**
             * Получаем список НОВЫХ финансовых выплат
             * по умолчанию за вчерашний день
             */

            $finances = $this->GetOzonOrderAccrualDayRequest
                ->forTokenIdentifier($OzonTokenUid)
                ->setDate($message->getDay())
                ->findAll();

            if(false === $finances || false === $finances->valid())
            {
                continue;
            }

            /** Получаем заказ по номеру отправления */
            foreach($finances as $OzonOrderAccrualDayResponse)
            {
                $Deduplicator = $this->Deduplicator
                    ->namespace('ozon-orders')
                    ->expiresAfter('1 hour')
                    ->deduplication([$OzonOrderAccrualDayResponse->getId(), self::class]);

                if($Deduplicator->isExecuted() && $message->isForce() === false)
                {
                    continue;
                }

                /** Пробуем получить объект */
                if(true === $message->isForce())
                {
                    $FinancesEvent = $this->CurrentFinancesEventByIdentifierRepository
                        ->find($OzonOrderAccrualDayResponse->getId());

                    /** Бросаем сообщение для перерасчета */
                    if(true === $FinancesEvent instanceof FinancesEvent)
                    {
                        $FinancesMessage = new FinancesMessage(
                            $FinancesEvent->getMain(),
                            $FinancesEvent->getId(),
                        )->force();

                        $this->messageDispatch->dispatch(
                            $FinancesMessage,
                            transport: 'finances',
                        );

                        $Deduplicator->save();
                        continue;
                    }
                }

                /** Проверяем наличие платежа */
                if(false === $message->isForce())
                {
                    /** Проверяем имеется ли такой платеж */
                    $isExist = $this->ExistFinanceRepository->exist($OzonOrderAccrualDayResponse->getId());

                    if(true === $isExist)
                    {
                        $Deduplicator->save();
                        continue;
                    }
                }

                $NewEditFinancesDTO = new NewEditFinancesDTO();
                $NewEditFinancesDTO
                    ->setPrice($OzonOrderAccrualDayResponse->getTotal())
                    ->setComment($OzonOrderAccrualDayResponse->getComment());

                $NewEditFinancesInvariableDTO = $NewEditFinancesDTO->getInvariable();
                $NewEditFinancesInvariableDTO
                    ->setCreated($OzonOrderAccrualDayResponse->getDate())
                    ->setUsr($User);

                $NewEditFinancesMarketplaceDTO = $NewEditFinancesDTO->getMarketpace();
                $NewEditFinancesMarketplaceDTO
                    ->setToken($OzonTokenUid->getValue())
                    ->setNumber($OzonOrderAccrualDayResponse->getNumber())
                    ->setIdentifier($OzonOrderAccrualDayResponse->getId());

                $NewEditPaymentDTO = $NewEditFinancesDTO->getPayment();
                $NewEditPaymentDTO->setValue(new PaymentUid(TypePaymentFbsOzon::TYPE));


                $OrderUid = $this->OrderByPostingRepository->find('O-'.$OzonOrderAccrualDayResponse->getNumber());

                /** Присваиваем идентификатор заказа если найден */
                if($OrderUid instanceof OrderUid)
                {
                    $NewEditFinancesOrderDTO = $NewEditFinancesDTO->getOrd();
                    $NewEditFinancesOrderDTO
                        ->setValue($OrderUid)
                        ->setFirst($OzonOrderAccrualDayResponse->getDate());
                }

                /** Если нет идентификатора заказа, но есть артикул продукта */
                if(false === ($OrderUid instanceof OrderUid) && $OzonOrderAccrualDayResponse->getArticle())
                {
                    $CurrentProductByBarcodeResult = $this->ProductConstByArticleRepository
                        ->find($OzonOrderAccrualDayResponse->getArticle());

                    if($CurrentProductByBarcodeResult instanceof CurrentProductByBarcodeResult)
                    {
                        $ProductInvariableUid = $CurrentProductByBarcodeResult->getInvariable();

                        if($ProductInvariableUid instanceof ProductInvariableUid)
                        {
                            $NewEditFinancesProductDTO = $NewEditFinancesDTO->getProduct();
                            $NewEditFinancesProductDTO->setValue($ProductInvariableUid);
                        }
                    }
                }

                /** @var NewEditFinancesHandler $NewEditFinancesHandler */
                $Finances = $this->NewEditFinancesHandler->handle($NewEditFinancesDTO);

                if(false === ($Finances instanceof Finances))
                {
                    $this->logger->critical(
                        sprintf('ozon-orders: Ошибка %s при добавлении платежа', $Finances),
                    );

                    continue;
                }

                $this->logger->info(
                    sprintf('%s: Добавили финансовую выплату', $Finances->getId()),
                    [self::class.':'.__LINE__],
                );
            }
        }

        $DeduplicatorExec->save();
    }
}
