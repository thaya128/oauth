<?php
// vim: foldmethod=marker
require_once('HTTP/OAuth/Consumer.php');

// {{{ TwitterApi11
/**
 * ツイッターOauth連携用クラス(twitter api ver.1.1)対応
 * <code>
 *
 * // preauth.php
 * require_once('TwitterApi11.class.php');
 * try{
 *     session_start();
 *     $twitter = new TwitterApi11( 'CONSUMER_KEY', 'CONSUMER_SECRET' , 'CALLBACK_URL' );
 *     $twitter->consumerAuth();
 * }catch( HTTP_OAuth_Exception $e){
 *     die($e->getMessage());
 * }
 *
 * // callback.php
 * require_once('TwitterApi11.class.php');
 * try{
 *     session_start();
 *     $twitter = new TwitterApi11( 'CONSUMER_KEY', 'CONSUMER_SECRET' , 'CALLBACK_URL' );
 *     $twitter->callback();
 * }catch( HTTP_OAuth_Exception $e){
 *     die($e->getMessage());
 * }
 *
 * // get_tweets.php
 * require_once('TwitterApi11.class.php');
 * try{
 *     session_start();
 *     $twitter = new TwitterApi11( 'CONSUMER_KEY', 'CONSUMER_SECRET' , 'CALLBACK_URL' );
 *     // qは必須
 *     $params = array('q'        => '#xxxxx',
 *                     'since_id' => 11111111111111111,
 *                     'count'    => 100 );
 *     echo $twitter->searchTweets( 'ACCESS_TOKEN', 'ACCESS_TOKEN_SECRET' , $params );
 * }catch( HTTP_OAuth_Exception $e){
 *     die($e->getMessage());
 * }
 * </code>
 * 現在の実装機能はツイート取得のみ
 * @see http://pear.php.net/package/HTTP_OAuth
 * @see https://dev.twitter.com/docs/api/1.1
 * @todo twitter api 1.1 RESTのすべての機能の追加
 */
class TwitterApi11
{
    const TWITTER_AUTH_URL                   = 'https://api.twitter.com/oauth/authorize';
    const TWITTER_CONSUMER_REQUEST_TOKEN_URL = 'https://api.twitter.com/oauth/request_token';
    const TWITTER_ACCESS_TOKEN_URL           = 'https://api.twitter.com/oauth/access_token';
    const TWEETS_SEARCH_URL                  = 'https://api.twitter.com/1.1/search/tweets.json';
    const TWEETS_PARAM_ERR                   = 'tweet parameter error.';
    const EXPLODE_VALUE1                     = '&';
    const EXPLODE_VALUE2                     = '=';
    const ERR_PREFIX_1                       = 'response:';
    const ERR_PREFIX_2                       = 'status code:';
    const CHECK_STATUS_CODE                  = 200;
    const TWITTER_AUTH_CONNECT_TIMEOUT       = 10;
    private $oauth  = null;
    private $status = null;

    // {{{ __construct
    /**
     * oauth認証準備
     * @params string $consumer_key コンシューマーキー
     * @params string $consumer_secret コンシューマシークレット
     * @params string $callback_url コールバック
     * @see http://pear.php.net/manual/en/package.http.http-request2.config.php
     */
    function __construct( $consumer_key , $consumer_secret , $callback_url ){
        $this->oauth  = new HTTP_OAuth_Consumer( $consumer_key , $consumer_secret );
        $http_request = new HTTP_Request2();
        $http_request->setConfig( array('ssl_verify_peer' => false,
                                        'connect_timeout' => self::TWITTER_AUTH_CONNECT_TIMEOUT ));
        $consumer_request = new HTTP_OAuth_Consumer_Request();
        $consumer_request->accept($http_request);
        $this->oauth->accept( $consumer_request );
        $_SESSION['twitter']['oauth']['consumer_key']    = $consumer_key;
        $_SESSION['twitter']['oauth']['consumer_secret'] = $consumer_secret;
        $_SESSION['twitter']['oauth']['callback']        = $callback_url;
    }
    // }}}

    // {{{ consumerAuth()
    /**
     * twitter認証画面へロケーション
     */
    public function consumerAuth()
    {
        $this->oauth->getRequestToken( self::TWITTER_CONSUMER_REQUEST_TOKEN_URL , $_SESSION['twitter']['oauth']['callback'] );
        $request_url = $this->oauth->getAuthorizeURL( self::TWITTER_AUTH_URL );
        $status      = $this->oauth->getLastResponse()->getResponse()->getStatus();
        if( $status != self::CHECK_STATUS_CODE )
            throw new HTTP_OAuth_Exception( self::ERR_PREFIX_1 . $this->oauth->getLastResponse()->getResponse()->getBody() . self::ERR_PREFIX_2 . $status );
        else{
            $_SESSION['twitter']['oauth']['request_token']        = $this->oauth->getToken();
            $_SESSION['twitter']['oauth']['request_token_secret'] = $this->oauth->getTokenSecret();
            header("Location: $request_url");
            exit;
        }
    }
    // }}}

    // {{{ callback()
    /**
     * callback関数
     */
    public function callback()
    {
        $this->oauth->setToken(       $_SESSION['twitter']['oauth']['request_token']  );
        $this->oauth->setTokenSecret( $_SESSION['twitter']['oauth']['request_token_secret'] );
        $this->oauth->getAccessToken( self::TWITTER_ACCESS_TOKEN_URL , $_REQUEST['oauth_verifier'] );
        $status = $this->oauth->getLastResponse()->getResponse()->getStatus();
        if( $status != self::CHECK_STATUS_CODE )
            throw new HTTP_OAuth_Exception( self::ERR_PREFIX_1 . $this->oauth->getLastResponse()->getResponse()->getBody() .self::ERR_PREFIX_2 . $status );
        else{
            $_SESSION['twitter']['oauth']['access_token']  = $this->oauth->getToken();
            $_SESSION['twitter']['oauth']['access_secret'] = $this->oauth->getTokenSecret();
            $body = explode( self::EXPLODE_VALUE1 , $this->oauth->getLastResponse()->getResponse()->getBody() );
            foreach( $body as $params ){
                if( strpos( $params , self::EXPLODE_VALUE2 ) !== false ){
                    list( $k , $v ) = explode( self::EXPLODE_VALUE2 , $params );
                    if( $k === 'user_id'     ) $_SESSION['twitter']['oauth'][$k] = $v;
                    if( $k === 'screen_name' ) $_SESSION['twitter']['oauth'][$k] = $v;
                }
            }
        }//end of if()
    }
    // }}}

    // {{{ getTweetUrlConvert()
    /**
     * ツイート取得文字列に変換
     * ※半角プラス文字列を含むプラス文字列をサーバ間通信（RFC1738）だとうまくいかないので、RFC3986のエンコードに直す
     * @params array $params GET値をセット
     * @return RFC3986の規格でURLエンコードされたリクエスト文字列
     */
    public function getTweetUrlConvert( $params ){
        return str_replace( array('+','%2D'), array('%20','-'), self::TWEETS_SEARCH_URL . '?' . http_build_query( $params ) );
    }
    // }}}

    // {{{ searchTweets()
    /**
     * ツイート情報を検索し取得する
     * @params string $access_token アクセストークン
     * @params string $access_secret アクセスシークレット
     * @params array $params リクエストパラメータ
     * @return mixed 成功:jsonデータ,失敗:false or throw
     */
    public function searchTweets( $access_token, $access_secret, $params ){
        $json = false;
        if( is_array( $params ) && array_key_exists( 'q', $params )){
            $this->oauth->setToken( $access_token );
            $this->oauth->setTokenSecret( $access_secret );
            $response = $this->oauth->sendRequest( $this->getTweetUrlConvert( $params ) , array(), 'GET');
            $status   = $this->oauth->getLastResponse()->getResponse()->getStatus();
            $json     = $response->getBody();
            if( $status != self::CHECK_STATUS_CODE )
                throw new HTTP_OAuth_Exception( self::ERR_PREFIX_1 .$json .self::ERR_PREFIX_2 .$status );
        }else
            throw new HTTP_OAuth_Exception( self::TWEETS_PARAM_ERR );
        
        return $json;
    }
    // }}}
}
