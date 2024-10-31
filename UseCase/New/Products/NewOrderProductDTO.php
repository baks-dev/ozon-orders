<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Ozon\Orders\UseCase\New\Products;

use BaksDev\Orders\Order\Entity\Products\OrderProduct;
use BaksDev\Orders\Order\Entity\Products\OrderProductInterface;
use BaksDev\Products\Product\Type\Event\ProductEventUid;
use BaksDev\Products\Product\Type\Offers\Id\ProductOfferUid;
use BaksDev\Products\Product\Type\Offers\Variation\Id\ProductVariationUid;
use BaksDev\Products\Product\Type\Offers\Variation\Modification\Id\ProductModificationUid;
use Symfony\Component\Validator\Constraints as Assert;

/** @see OrderProduct */
final class NewOrderProductDTO implements OrderProductInterface
{
    /** Артикул продукта */
    #[Assert\NotBlank]
    private string $article;

    /** SKU продукта */
    #[Assert\NotBlank]
    private int $sku;

    /** Событие продукта */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    private ProductEventUid $product;

    /** Торговое предложение */
    #[Assert\Uuid]
    private ?ProductOfferUid $offer = null;

    /** Множественный вариант торгового предложения */
    #[Assert\Uuid]
    private ?ProductVariationUid $variation = null;

    /** Модификация множественного варианта торгового предложения  */
    #[Assert\Uuid]
    private ?ProductModificationUid $modification = null;

    /** Стоимость и количество */
    #[Assert\Valid]
    private Price\NewOrderPriceDTO $price;

    public function __construct(string $article)
    {
        $this->article = $article;
        $this->price = new Price\NewOrderPriceDTO();

    }

    /**
     * Article
     */
    public function getArticle(): string
    {
        return $this->article;
    }


    /** Событие продукта */
    public function getProduct(): ProductEventUid
    {
        return $this->product;
    }

    public function setProduct(ProductEventUid|string $product): self
    {
        if(isset($product) && is_string($product))
        {
            $product = new ProductEventUid($product);
        }

        $this->product = $product;

        return $this;
    }


    /** Торговое предложение */
    public function getOffer(): ?ProductOfferUid
    {
        return $this->offer;
    }

    public function setOffer(ProductOfferUid|string|null $offer): self
    {
        if(isset($offer) && is_string($offer))
        {
            $offer = new ProductOfferUid($offer);
        }

        $this->offer = $offer;

        return $this;
    }

    /** Множественный вариант торгового предложения */
    public function getVariation(): ?ProductVariationUid
    {
        return $this->variation;
    }

    public function setVariation(ProductVariationUid|string|null $variation): self
    {
        if(isset($variation) && is_string($variation))
        {
            $variation = new ProductVariationUid($variation);
        }


        $this->variation = $variation;
        return $this;
    }

    /** Модификация множественного варианта торгового предложения  */
    public function getModification(): ?ProductModificationUid
    {
        return $this->modification;
    }

    public function setModification(ProductModificationUid|string|null $modification): self
    {
        if(isset($modification) && is_string($modification))
        {
            $modification = new ProductModificationUid($modification);
        }

        $this->modification = $modification;
        return $this;
    }

    /** Стоимость и количество */
    public function getPrice(): Price\NewOrderPriceDTO
    {
        return $this->price;
    }

    public function setPrice(Price\NewOrderPriceDTO $price): void
    {
        $this->price = $price;
    }

    /**
     * Sku
     */
    public function getSku(): int
    {
        return $this->sku;
    }

    public function setSku(int $sku): self
    {
        $this->sku = $sku;
        return $this;
    }
}
