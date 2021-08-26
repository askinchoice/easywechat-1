<?php

/*
 * This file is part of the overtrue/wechat.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace EasyWeChat\Tests\Payment\Base;

use EasyWeChat\Payment\Application;
use EasyWeChat\Payment\Base\Client;
use EasyWeChat\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\HandlerStack;

class ClientTest extends TestCase
{
    public function testPay()
    {
        $app = new Application(['app_id' => 'mock-appid']);

        $client = $this->mockApiClient(Client::class, ['pay'], $app)->makePartial();

        $order = [
            'appid' => 'mock-appid',
            'foo' => 'bar',
        ];

        $client->expects()->request('pay/micropay', $order)->andReturn('mock-result');
        $this->assertSame('mock-result', $client->pay($order));
    }

    public function testAuthCodeToOpenid()
    {
        $app = new Application(['app_id' => 'mock-appid']);

        $client = $this->mockApiClient(Client::class, 'authCodeToOpenId', $app)->makePartial();

        $client->expects()->request('tools/authcodetoopenid', [
            'appid' => 'mock-appid',
            'auth_code' => 'foo',
            ])->andReturn('mock-result');

        $this->assertSame('mock-result', $client->authCodeToOpenid('foo'));
    }

    public function testRequestV3Certificates()
    {
        $app = new Application();

        $client = $this->mockApiClient(
            Client::class,
            ['castResponseToType', 'performRequest', 'generateHandlerStack', 'pushMiddleware', 'setClientHeadersMiddleware', 'authorizedV3Middleware', 'logMiddleware'],
            $app
        );

        $client->expects()->pushMiddleware(\Closure::class, 'client_headers');

        $client->expects()->pushMiddleware(\Closure::class, 'wechat_authorized');

        $client->expects()->pushMiddleware(\Closure::class, 'log');

        $client->expects()->setClientHeadersMiddleware()->andReturn(fn () => '');

        $client->expects()->authorizedV3Middleware()->andReturn(fn () => '');

        $client->expects()->logMiddleware()->andReturn(fn () => '');

        $client->expects()->castResponseToType(ResponseInterface::class, null)->andReturn('mock-result');

        $client->expects()->performRequest(
            'https://api.mch.weixin.qq.com/v3/certificates',
            'GET',
            ['handler' => \Mockery::mock(HandlerStack::class)]
        )->andReturn(\Mockery::mock(ResponseInterface::class));

        $client->expects()->generateHandlerStack('client_headers', 'wechat_authorized', 'log')->andReturn(\Mockery::mock(HandlerStack::class));

        $this->assertSame('mock-result', $client->requestV3Certificates());
    }
}
