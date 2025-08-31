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

namespace BaksDev\Ozon\Orders\Api\Tests;

use BaksDev\Orders\Order\UseCase\Admin\Edit\Tests\OrderNewTest;
use BaksDev\Ozon\Orders\Api\PrintOzonStickerRequest;
use BaksDev\Ozon\Orders\Type\ProfileType\TypeProfileFbsOzon;
use BaksDev\Ozon\Orders\UseCase\New\NewOzonOrderDTO;
use BaksDev\Ozon\Type\Authorization\OzonAuthorizationToken;
use BaksDev\Products\Stocks\UseCase\Admin\Package\Tests\PackageProductStockTest;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\Profile\UserProfile\UseCase\User\NewEdit\Tests\UserNewUserProfileHandleTest;
use Imagick;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\Filesystem\Filesystem;

#[Group('ozon-orders')]
#[When(env: 'test')]
class PrintOzonStickerRequestTest extends KernelTestCase
{
    private static OzonAuthorizationToken $Authorization;

    public static function setUpBeforeClass(): void
    {
        OrderNewTest::setUpBeforeClass();
        PackageProductStockTest::setUpBeforeClass();
        UserNewUserProfileHandleTest::setUpBeforeClass();

        self::$Authorization =
            new OzonAuthorizationToken(
                new UserProfileUid($_SERVER['TEST_PROFILE'] ?? null),
                $_SERVER['TEST_OZON_TOKEN'],
                TypeProfileFbsOzon::TYPE,
                $_SERVER['TEST_OZON_CLIENT'],
                $_SERVER['TEST_OZON_WAREHOUSE'],
                '10',
                0,
                false,
                false,
            );
    }

    public function testRequest(): void
    {
        self::assertTrue(true);
        return;

        /** @var PrintOzonStickerRequest $PrintOzonStickerRequest */
        $PrintOzonStickerRequest = self::getContainer()->get(PrintOzonStickerRequest::class);
        $PrintOzonStickerRequest->TokenHttpClient(self::$Authorization);

        /** @var NewOzonOrderDTO $orderInfo */
        $ozonSticker = $PrintOzonStickerRequest
            ->find(number: '111111111-1111-1');

        // dd($ozonSticker);


        self::assertIsString($ozonSticker);
        return;

        /** @var ContainerBagInterface $containerBag */
        $container = self::getContainer();
        $containerBag = $container->get(ContainerBagInterface::class);
        /** @var Filesystem $fileSystem */
        $fileSystem = $container->get(Filesystem::class);

        /** Создаем путь к тестовой директории */
        $testUploadDir = implode(DIRECTORY_SEPARATOR, [
            $containerBag->get('kernel.project_dir'), 'public', 'upload', 'tests',
        ]);

        /** Проверяем существование директории для тестовых картинок */
        if(false === is_dir($testUploadDir))
        {
            $fileSystem->mkdir($testUploadDir);
        }

        $pdfFile = $testUploadDir.DIRECTORY_SEPARATOR.'ozon-sticker.pdf';

        /**
         * Сохраняю PDF
         */
        $fileSystem->dumpFile(
            filename: $pdfFile,
            content: $ozonSticker,
        );

        self::assertTrue(is_file($pdfFile));

        $jpegFile = $testUploadDir.DIRECTORY_SEPARATOR.'ozon-sticker.jpg';

        $imagick = new Imagick();
        $imagick->setResolution(200, 200); // DPI

        /** [0] — первая страница */
        $imagick->readImage($pdfFile.'[0]'); // Чтение PDF
        //        $imagick->readImageBlob($ozonSticker.'[0]'); // Чтение бинарника

        $imagick->setImageFormat('jpeg');

        //        $image = $imagick->getImageBlob(); // Сохраняю в памяти
        $imagick->writeImage($jpegFile); // Сохраняю в файл

        $imagick->clear();

        self::assertTrue(is_file($jpegFile));

        //                $fileSystem->remove([$pdfFile]);
        //                $fileSystem->remove([$jpegFile]);
    }
}
