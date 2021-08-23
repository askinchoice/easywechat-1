<?php

/*
 * This file is part of the overtrue/wechat.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace EasyWeChat\Payment\Kernel;

use EasyWeChat\Kernel\Support;
use EasyWeChat\Kernel\Traits\HasHttpRequests;
use EasyWeChat\Kernel\Traits\InteractsWithV3Api;
use EasyWeChat\Payment\Application;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Class BaseClient.
 *
 * @author overtrue <i@overtrue.me>
 */
class BaseClient
{
    use InteractsWithV3Api, HasHttpRequests { request as performRequest; }

    /**
     * @var \EasyWeChat\Payment\Application
     */
    protected $app;

    /**
     * Constructor.
     *
     * @param \EasyWeChat\Payment\Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;

        $this->setHttpClient($this->app['http_client']);
    }

    /**
     * Extra request params.
     *
     * @return array
     */
    protected function prepends()
    {
        return [];
    }

    /**
     * Make a API request.
     *
     * @param string $endpoint
     * @param array  $params
     * @param string $method
     * @param array  $options
     * @param bool   $returnResponse
     *
     * @return \Psr\Http\Message\ResponseInterface|\EasyWeChat\Kernel\Support\Collection|array|object|string
     *
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function request(string $endpoint, array $params = [], $method = 'post', array $options = [], $returnResponse = false)
    {
        $base = [
            'mch_id' => $this->app['config']['mch_id'],
            'nonce_str' => uniqid(),
            'sub_mch_id' => $this->app['config']['sub_mch_id'],
            'sub_appid' => $this->app['config']['sub_appid'],
        ];

        $params = array_filter(array_merge($base, $this->prepends(), $params), 'strlen');

        $secretKey = $this->app->getKey($endpoint);

        $encryptMethod = Support\get_encrypt_method(Support\Arr::get($params, 'sign_type', 'MD5'), $secretKey);

        $params['sign'] = Support\generate_sign($params, $secretKey, $encryptMethod);

        $options = array_merge([
            'body' => Support\XML::build($params),
        ], $options);

        $this->pushMiddleware($this->logMiddleware(), 'log');

        $response = $this->performRequest($endpoint, $method, $options);

        return $returnResponse ? $response : $this->castResponseToType($response, $this->app->config->get('response_type'));
    }

    /**
     * Make a request to V3 API.
     *
     * @param string $endpoint
     * @param array  $params
     * @param string $method
     * @param array  $options
     * @param bool   $returnResponse
     *
     * @return \Psr\Http\Message\ResponseInterface|\EasyWeChat\Kernel\Support\Collection|array|object|string
     *
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \EasyWeChat\Kernel\Exceptions\DecryptException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function requestToV3(string $endpoint, array $params = [], $method = 'post', array $options = [], $returnResponse = false)
    {
        $base = [
            'sub_mchid' => $this->app['config']['sub_mch_id'],
            'sub_appid' => $this->app['config']['sub_appid'],
        ];

        $params = array_filter(array_merge($base, $this->prepends(), $params), fn ($item) => !is_null($item) && $item !== '');

        $options = array_merge([
            'json' => $params,
        ], $options);

        $this->pushMiddleware($this->setClientHeaders(), 'client_headers');

        $this->pushMiddleware($this->authorizedV3Middleware(), 'wechat_authorized');

        $this->pushMiddleware($this->validateV3SignatureMiddleware(), 'validate_wechat_v3_signature');

        $this->pushMiddleware($this->logMiddleware(), 'log');

        $response = $this->performRequest($endpoint, $method, $options);

        return $returnResponse ? $response : $this->castResponseToType($response, $this->app->config->get('response_type'));
    }

    /**
     * Log the request.
     *
     * @return \Closure
     */
    protected function logMiddleware()
    {
        $formatter = new MessageFormatter($this->app['config']['http.log_template'] ?? MessageFormatter::DEBUG);

        return Middleware::log($this->app['logger'], $formatter);
    }

    /**
     * Set client headers.
     *
     * @return \Closure
     */
    protected function setClientHeaders()
    {
        return Middleware::mapRequest(
            fn (RequestInterface $request) => $request
            ->withHeader('Accept', 'application/json, text/plain, application/x-gzip')
            ->withHeader('User-Agent', 'overtrue/wechat')
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
        );
    }

    /**
     * Authorize the request.
     *
     * @return \Closure
     */
    protected function authorizedV3Middleware()
    {
        $timestamp = time();

        $nonceStr = uniqid();

        return Middleware::mapRequest(fn (RequestInterface $request) => $request->withHeader('Authorization', $this->generateToken(
            $this->app['config']['mch_id'],
            $nonceStr,
            $timestamp,
            $this->app['config']['serial_no'],
            Support\sha256_rsa_with_private_encrypt(
                $this->getContents($request, $timestamp, $nonceStr),
                openssl_pkey_get_private('file://'.$this->app['config']['v3_key_path'])
            ),
        )));
    }

    /**
     * Validate wechat V3 response Signature.
     *
     * @return \Closure
     */
    protected function validateV3SignatureMiddleware()
    {
        return Middleware::mapResponse(fn (ResponseInterface $response) => $this->validateWechatSignature($response));
    }

    /**
     * Make a request and return raw response.
     *
     * @param string $endpoint
     * @param array  $params
     * @param string $method
     * @param array  $options
     *
     * @return ResponseInterface
     *
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function requestRaw(string $endpoint, array $params = [], $method = 'post', array $options = [])
    {
        /** @var ResponseInterface $response */
        $response = $this->request($endpoint, $params, $method, $options, true);

        return $response;
    }

    /**
     * Make a request and return an array.
     *
     * @param string $endpoint
     * @param array  $params
     * @param string $method
     * @param array  $options
     *
     * @return array
     *
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function requestArray(string $endpoint, array $params = [], $method = 'post', array $options = []): array
    {
        $response = $this->requestRaw($endpoint, $params, $method, $options);

        return $this->castResponseToType($response, 'array');
    }

    /**
     * Request with SSL.
     *
     * @param string $endpoint
     * @param array  $params
     * @param string $method
     * @param array  $options
     *
     * @return \Psr\Http\Message\ResponseInterface|\EasyWeChat\Kernel\Support\Collection|array|object|string
     *
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidConfigException
     * @throws \EasyWeChat\Kernel\Exceptions\InvalidArgumentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function safeRequest($endpoint, array $params, $method = 'post', array $options = [])
    {
        $options = array_merge([
            'cert' => $this->app['config']->get('cert_path'),
            'ssl_key' => $this->app['config']->get('key_path'),
        ], $options);

        return $this->request($endpoint, $params, $method, $options);
    }

    /**
     * Wrapping an API endpoint.
     *
     * @param string $endpoint
     *
     * @return string
     */
    protected function wrap(string $endpoint): string
    {
        return $this->app->inSandbox() ? "sandboxnew/{$endpoint}" : $endpoint;
    }
}
