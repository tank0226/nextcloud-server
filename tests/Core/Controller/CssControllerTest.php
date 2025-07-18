<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Tests\Core\Controller;

use OC\Core\Controller\CssController;
use OC\Files\AppData\AppData;
use OC\Files\AppData\Factory;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IRequest;
use Test\TestCase;

class CssControllerTest extends TestCase {
	/** @var IAppData|\PHPUnit\Framework\MockObject\MockObject */
	private $appData;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var CssController */
	private $controller;

	protected function setUp(): void {
		parent::setUp();

		/** @var Factory|\PHPUnit\Framework\MockObject\MockObject $factory */
		$factory = $this->createMock(Factory::class);
		$this->appData = $this->createMock(AppData::class);
		$factory->expects($this->once())
			->method('get')
			->with('css')
			->willReturn($this->appData);

		/** @var ITimeFactory|\PHPUnit\Framework\MockObject\MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')
			->willReturn(1337);

		$this->request = $this->createMock(IRequest::class);

		$this->controller = new CssController(
			'core',
			$this->request,
			$factory,
			$timeFactory
		);
	}

	public function testNoCssFolderForApp(): void {
		$this->appData->method('getFolder')
			->with('myapp')
			->willThrowException(new NotFoundException());

		$result = $this->controller->getCss('file.css', 'myapp');

		$this->assertInstanceOf(NotFoundResponse::class, $result);
	}


	public function testNoCssFile(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$this->appData->method('getFolder')
			->with('myapp')
			->willReturn($folder);

		$folder->method('getFile')
			->willThrowException(new NotFoundException());

		$result = $this->controller->getCss('file.css', 'myapp');

		$this->assertInstanceOf(NotFoundResponse::class, $result);
	}

	public function testGetFile(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('my name');
		$file->method('getMTime')->willReturn(42);
		$this->appData->method('getFolder')
			->with('myapp')
			->willReturn($folder);

		$folder->method('getFile')
			->with('file.css')
			->willReturn($file);

		$expected = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => 'text/css']);
		$expected->addHeader('Cache-Control', 'max-age=31536000, immutable');
		$expires = new \DateTime();
		$expires->setTimestamp(1337);
		$expires->add(new \DateInterval('PT31536000S'));
		$expected->addHeader('Expires', $expires->format(\DateTime::RFC1123));

		$result = $this->controller->getCss('file.css', 'myapp');
		$this->assertEquals($expected, $result);
	}

	public function testGetGzipFile(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$gzipFile = $this->createMock(ISimpleFile::class);
		$gzipFile->method('getName')->willReturn('my name');
		$gzipFile->method('getMTime')->willReturn(42);
		$this->appData->method('getFolder')
			->with('myapp')
			->willReturn($folder);

		$folder->method('getFile')
			->with('file.css.gzip')
			->willReturn($gzipFile);

		$this->request->method('getHeader')
			->with('Accept-Encoding')
			->willReturn('gzip, deflate');

		$expected = new FileDisplayResponse($gzipFile, Http::STATUS_OK, ['Content-Type' => 'text/css']);
		$expected->addHeader('Content-Encoding', 'gzip');
		$expected->addHeader('Cache-Control', 'max-age=31536000, immutable');
		$expires = new \DateTime();
		$expires->setTimestamp(1337);
		$expires->add(new \DateInterval('PT31536000S'));
		$expected->addHeader('Expires', $expires->format(\DateTime::RFC1123));

		$result = $this->controller->getCss('file.css', 'myapp');
		$this->assertEquals($expected, $result);
	}

	public function testGetGzipFileNotFound(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn('my name');
		$file->method('getMTime')->willReturn(42);
		$this->appData->method('getFolder')
			->with('myapp')
			->willReturn($folder);

		$folder->method('getFile')
			->willReturnCallback(
				function ($fileName) use ($file) {
					if ($fileName === 'file.css') {
						return $file;
					}
					throw new NotFoundException();
				}
			);

		$this->request->method('getHeader')
			->with('Accept-Encoding')
			->willReturn('gzip, deflate');

		$expected = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => 'text/css']);
		$expected->addHeader('Cache-Control', 'max-age=31536000, immutable');
		$expires = new \DateTime();
		$expires->setTimestamp(1337);
		$expires->add(new \DateInterval('PT31536000S'));
		$expected->addHeader('Expires', $expires->format(\DateTime::RFC1123));

		$result = $this->controller->getCss('file.css', 'myapp');
		$this->assertEquals($expected, $result);
	}
}
