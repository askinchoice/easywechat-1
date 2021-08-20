<?php

/*
 * This file is part of the overtrue/wechat.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace EasyWeChat\Kernel\Traits;

use Psr\Http\Message\RequestInterface;

/**
 * Trait InteractsWithV3Api.
 */
trait InteractsWithV3Api
{
    /**
     * Generate V3 API token.
     *
     * @param string $merchantId
     * @param string $nonceStr
     * @param string $timestamp
     * @param string $serialNumber
     * @param string $signature
     *
     * @return string
     */
    public function generateToken(
        string $merchantId,
        string $nonceStr,
        string $timestamp,
        string $serialNumber,
        string $signature,
    ) {
        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%d",serial_no="%s",signature="%s"',
            $merchantId,
            $nonceStr,
            $timestamp,
            $serialNumber,
            $signature,
        );
    }

    /**
     * Get request contents according V3 rule.
     *
     * @param RequestInterface $request
     * @param string $timestamp
     * @param string $nonceStr
     *
     * @return string
     */
    public function getContents(RequestInterface $request, string $timestamp, string $nonceStr)
    {
        return $request->getMethod()."\n".
            $request->getRequestTarget()."\n".
            $timestamp."\n".
            $nonceStr."\n".
            $request->getBody()."\n";
    }
}
