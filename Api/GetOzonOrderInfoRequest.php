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

namespace BaksDev\Ozon\Orders\Api;

use BaksDev\Ozon\Api\Ozon;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use DateTimeImmutable;

/**
 * Информация о заказах
 */
final class GetOzonOrderInfoRequest extends Ozon
{
    private ?DateTimeImmutable $fromDate = null;

    /**
     * Получить информацию об отправлении по идентификатору
     *
     * @see https://docs.ozon.ru/api/seller/#operation/PostingAPI_GetFbsPostingV3
     *
     */
    public function find(string $number): NewOzonOrderDTO|false
    {
        $number = str_replace('O-', '', $number);

        $data['posting_number'] = $number;

        $response = $this->TokenHttpClient()
            ->request(
                'POST',
                '/v3/posting/fbs/get',
                ['json' => $data],
            );

        $content = $response->toArray(false);

        if($response->getStatusCode() !== 200)
        {
            foreach($content['errors'] as $error)
            {
                $this->logger->critical($error['code'].': '.$error['message'], [self::class.':'.__LINE__]);
            }

            return false;
        }

        return new NewOzonOrderDTO(current($content), $this->getProfile());

    }
}