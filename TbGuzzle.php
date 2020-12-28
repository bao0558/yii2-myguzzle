<?php

namespace bao0558\guzzle;

use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Yii;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * yii2-guzzle implementation, based on this library:
 * https://github.com/guzzle/guzzle
 *
 * @author Tangbao <768588658@qq.com>
 * @since 1.0.0-a
 */
class TbGuzzle extends Component
{
    public $time_out        = 3 ;   //seconds 请求超时的秒数  请求成功等待返回值的时间
    public $connect_timeout = 3 ;   //seconds 等待服务器响应超时的时间  尝试链接服务器的时间
    public $delay           = 0 ;   //ms      延迟发送时间
    public $retry_time      = 200 ; //ms      重试间隔
    public $retry_num       = 5 ;   //重试次数
    public $debug           = false ;
    public $data            = [];//请求信息
    public $stack           = [];//中间件
    public $client          = [];//客户端
    
    /**
     * 发起请求
     * @param string $url
     * @param string $method
     * @param false  $async
     * @param array  $data
     * @param array  $before
     * @param array  $header
     * @param array  $middle
     *
     * @return mixed
     */
    public function request( string $url = '' , string $method = 'POST' ,$async = false , array $data =[] , array $before, array $header = [] , array $middle = [] )
    {
        //前置请求
        $this->getBefore( $before , $middle );
        if ( $data ) {
            $data = json_encode($data,JSON_UNESCAPED_UNICODE);
            $this->data['body'] = $data;
        }
        //判断是同步还是异步请求
        if ( $async ) {
            $promise = $this->client->requestAsync($method,$url,$this->data);
            $promise->then(
                function ( ResponseInterface $res ) {//正确返回
                    if ( $res->getStatusCode == 200 ) {
                        $response = $res->getBody->getContents();
                    } else {
                        $response = '';
                    }
                },
                function ( RequestException $e ) {//请求出错
                    $response = $e->getMessage();
                },
            );
        } else {
            $result = $this->client->request($method,$url,$this->data);
            $response = $result->getBody->getContents();
        }
        //返回结果
        return $response;
    }
    
    /**
     * 前置操作
     *
     * @param array $before
     * @param array $middle
     */
    public function getBefore( array $before = [] , array $middle = [] )
    {
        //请求参数 需要在头之前
        $this->before( $before );
        //请求头
        if ( $header ) {
            $this->setHeader( $header );
        }
        //中间件
        if ( $middle ) {
            $this->setMiddleware( $middle );
        }
        //实例化客户端
        if ( $this->stack ) { //如果设置了中间件
            $this->client = new Client( ['handler'=> $this->stack] );
        } else {
            $this->client = new Client();
        }
    }

    /**
     * 设置发送头
     * @param array $headers
     */
    public function setHeader( array $headers )
    {
        $this->$data['header'] = $headers;
    }

    /**
     * @param array $middle
     * @return void|null
     */
    public function setMiddleware( array $middle )
    {
        $handler    = new CurlHandler();
        $stack      = HandlerStack::create($handler);
        if ( $middle['retry']  ) {
            if( $middle['retry']['delay'] ) {
                $this->retry_time = $middle['retry']['time'];
            }
            $this->stack->push(Middleware::retry($this->retryDecider(),$this->retryDelay()));
        }
        return null;
    }

    /**
     * 设定请求前参数
     * @param array $before
     */
    public function before( array $before )
    {
        //超时设定 time_out 延迟设定 delay debug设定 debug
        if ( $before ) {
            $this->data = $before;
        } else {
            $this->data['timeout']          = $this->time_out;
            $this->data['connect_timeout']  = $this->connect_timeout;
            $this->data['delay']            = $this->delay;
            $this->data['debug']            = $this->debug;
        }
    }

    /**
     * retryDecider
     * 返回一个匿名函数, 匿名函数若返回false 表示不重试，反之则表示继续重试
     * @return Closure
     */
    protected function retryDecider()
    {
        return function (
            $retries,
            Request $request,
            Response $response = null,
            RequestException $exception = null
        ) {
            // 超过最大重试次数，不再重试
            if ($retries >= $this->retry_num) {
                return false;
            }

            // 请求失败，继续重试
            if ($exception instanceof ConnectException) {
                return true;
            }

            if ($response) {
                // 如果请求有响应，但是状态码等于429，继续重试(这里根据自己的业务而定)  429 短时间内请求次数过高
                if ( $response->getStatusCode() == 429 ) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * 返回一个匿名函数，该匿名函数返回下次重试的时间（毫秒）
     * @return Closure
     */
    protected function retryDelay()
    {
        return function ($numberOfRetries) {
            return $this->retry_time * $numberOfRetries;
        };
    }
}
