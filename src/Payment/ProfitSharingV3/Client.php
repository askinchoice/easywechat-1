<?php

/*
 * This file is part of the overtrue/wechat.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace EasyWeChat\Payment\ProfitSharingV3;

use EasyWeChat\Payment\Kernel\BaseClient;

/**
 * Class Client.
 *
 * @author circle33 <newnash13@gmail.com>
 */
class Client extends BaseClient
{
    /**
     * {@inheritdoc}.
     */
    protected function prepends()
    {
        return [
            'sign_type' => 'HMAC-SHA256',
        ];
    }

    /**
     * Profit sharing.
     * 请求分账.
     *
     * @param string $transactionId 微信支付订单号
     * @param string $outOrderNo    商户系统内部的分账单号
     * @param array  $receivers     分账接收方列表
     * @param bool   $finish        是否分账完成
     *
     * @return array|\EasyWeChat\Kernel\Support\Collection|object|\Psr\Http\Message\ResponseInterface|string
     *
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function share(
        string $transactionId,
        string $outOrderNo,
        array $receivers,
        bool  $finish = false,
    ) {
        $params = [
            'appid' => $this->app['config']->app_id,
            'transaction_id' => $transactionId,
            'out_order_no' => $outOrderNo,
            'receivers' => $receivers,
            'finish' => $finish,
        ];

        return $this->requestToV3(
            'v3/brand/profitsharing/orders',
            $params
        );
    }
}
