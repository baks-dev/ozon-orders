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

namespace BaksDev\Ozon\Orders\Api\Accrual;

use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Ozon\Api\Ozon;
use BaksDev\Ozon\Products\Api\Card\Identifier\GetOzonCardNameRequest;
use BaksDev\Ozon\Products\Api\Card\Identifier\GetOzonCardOfferIdRequest;
use BaksDev\Ozon\Repository\OzonToken\OzonTokenInterface;
use BaksDev\Ozon\Repository\OzonTokensByProfile\OzonTokensByProfileInterface;
use BaksDev\Ozon\Type\Id\OzonTokenUid;
use DateInterval;
use DateTimeImmutable;
use Generator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Информация о заказах
 */
#[Autoconfigure(public: true, shared: false)]
final class GetOzonOrderAccrualDayRequest extends Ozon
{
    public ?DateTimeImmutable $date = null;
    private ?string $last = null;

    public function __construct(
        #[Autowire(env: 'APP_ENV')] string $environment,
        #[Target('ozonLogger')] LoggerInterface $logger,
        OzonTokenInterface $OzonToken,
        OzonTokensByProfileInterface $OzonTokensByProfile,
        AppCacheInterface $cache,

        private readonly GetOzonCardOfferIdRequest $GetOzonCardOfferIdRequest,
    )
    {
        parent::__construct($environment, $logger, $OzonToken, $OzonTokensByProfile, $cache);
    }

    public function setDate(?DateTimeImmutable $date): self
    {
        $this->last = null;
        $this->date = $date;
        return $this;
    }

    /**
     * Получить информацию об отправлении по идентификатору
     *
     * @see https://docs.ozon.ru/api/seller/#operation/GetFinanceAccrualByDay
     *
     * @return Generator<OzonOrderAccrualDayResponse>|bool
     *
     */
    public function findAll()//: Generator|bool
    {
        while(true)
        {
            $data = [
                "date" => $this->date
                    ? $this->date->format('Y-m-d')
                    : new DateTimeImmutable('now')->sub(DateInterval::createFromDateString('1 day'))->format('Y-m-d'),
                "last_id" => $this->last,
            ];

            $response = $this->TokenHttpClient()
                ->request(
                    'POST',
                    '/v1/finance/accrual/by-day',
                    ['json' => $data],
                );

            $content = $response->toArray(false);

            if($response->getStatusCode() !== 200)
            {
                $this->logger->critical(
                    sprintf(
                        'ozon-orders: Ошибка %s при получении информации о выплатах за день',
                        $response->getStatusCode(),
                    ),
                    [self::class.':'.__LINE__, $data, $content],
                );

                $this->last = null;

                break;
            }

            if(empty($content['accruals']))
            {
                $this->last = null;

                break;
            }

            $this->last = $content['last_id'];

            foreach($content['accruals'] as $accrual)
            {
                $article = null;

                /** Если начисление по товару */
                if($accrual['accrued_category'] === 'ITEM' && true === ($this->getIdentifier() instanceof OzonTokenUid))
                {
                    // получаем по SKU артикул товара
                    foreach($accrual['item_fees'] as $fees)
                    {
                        foreach($fees as $fee)
                        {
                            $article = $this->GetOzonCardOfferIdRequest
                                ->forTokenIdentifier($this->getIdentifier())
                                ->sku($fee['sku'])
                                ->find();

                        }
                    }
                }

                yield new OzonOrderAccrualDayResponse($accrual, $article);
            }

            if(count($content['accruals']) < 1000)
            {
                $this->last = null;
                break;
            }
        }

        return true;
    }
}