<?php

namespace Drupal\vlr_sharepoint;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\key\KeyRepository;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class SharepointHelper.
 */
class SharepointHelper {

  /**
   * @var string
   */
  var $url;

  /**
   * @var string
   */
  var $username;

  /**
   * @var string
   */
  var $password;

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  var $cacheDefault;

  /**
   * @var \Drupal\key\KeyRepository
   */
  var $keyRepository;

  /**
   * SharepointHelper constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_default
   *   cache.default service.
   * @param \Drupal\key\KeyRepository $key_repository
   *   key.repository service.
   */
  public function __construct(CacheBackendInterface $cache_default, KeyRepository $key_repository) {
    $this->cacheDefault = $cache_default;
    $this->keyRepository = $key_repository;
  }

  /**
   * Sets $url value.
   *
   * @param string $url
   *   URL taken from webform handler config.
   */
  public function setUrl($url) {
    $parsed_url = parse_url($url);
    $this->url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . '/';
  }

  /**
   * Sets Sharepoint user.
   *
   * @param string $key_id
   *   Key id.
   *
   * @throws \Exception
   */
  public function setUser($key_id) {
    $key = $this->keyRepository->getKey($key_id);
    $credentials = $key->getKeyValues();
    if (empty($credentials)) throw new \Exception('No Sharepoint credentials found');
    $this->username = $credentials['username'];
    $this->password = $credentials['password'];
  }

  /**
   * Gets Sharepoint authentication cookies and X-RequestDigest header.
   *
   * @param bool $auth
   *   Flag to refresh security and access tokens.
   *
   * @return array
   *   'cookies' - array of cookies, 'digest' - X-RequestDigest header string.
   */
  public function getAuthData($auth = FALSE) {
    $cid = 'vlr_sharepoint:access_token';

    if (!$auth) {
      $data_cached = $this->cacheDefault->get($cid);
      $data_cached ? $cookies = $data_cached->data : $auth = TRUE;
    }

    // Refresh security and access tokens.
    if ($auth) {
      $secret_token = $this->getSecretToken();
      $cookies = $this->getAccessToken($secret_token);
      $this->cacheDefault->set($cid, $cookies);
    }

    $digest = $this->getDigest($cookies);

    // 403 likely means that the tokens got expired.
    if ($digest['http_code'] != 200 && !$auth) return $this->getAuthData(TRUE);

    return [
      'cookies' => $cookies,
      'digest' => $this->getXmlNode($digest['xml'], 'd|GetContextWebInformation d|FormDigestValue'),
    ];
  }

  /**
   * Gets Sharepoint secret token value.
   *
   * @return string
   *   Sharepoint secret token value.
   */
  protected function getSecretToken() {
    $body = $this->generateSecurityTokenRequestBody();
    $xml = $this->getSecretTokenXml($body);
    return $this->getXmlNode($xml, 'S|Envelope S|Body wst|RequestSecurityTokenResponse wst|RequestedSecurityToken wsse|BinarySecurityToken');
  }

  /**
   * Gets Sharepoint secret token XML response.
   *
   * @param $body
   *   Secret token request body.
   *
   * @return bool|string
   *
   * @see generateSecurityTokenRequestBody()
   */
  protected function getSecretTokenXml($body) {
    $url = 'https://login.microsoftonline.com/extSTS.srf';
    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_HTTPHEADER => [
        "Content-Type: text/plain",
      ],
    ]);
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;
  }

  /**
   * Gets Sharepoint access token.
   *
   * @param $body
   *   Request body.
   *
   * @return array
   *   Array of cookies.
   *
   * @see getCurlResponseHeaders()
   * @see getCookies()
   */
  protected function getAccessToken($body) {
    $url = $this->url . '_forms/default.aspx?wa=wsignin1.0';
    $parsed_url = parse_url($url);

    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_HTTPHEADER => [
        "Host: " . $parsed_url['host'],
        'Content-Length:' . strlen($body),
      ],
      CURLOPT_HEADER => TRUE,
    ]);
    $response = curl_exec($curl);
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);

    $headers = $this->parseCurlResponseHeaders($response, $header_size);
    return $this->getCookies($headers, ['rtFa', 'FedAuth']);
  }

  /**
   * Parses cURL response headers.
   *
   * @param string $response
   *   cURL response.
   * @param int $header_size
   *   cURL headers size.
   *
   * @return array
   *   Headers array.
   */
  protected function parseCurlResponseHeaders($response, $header_size) {
    $headers = substr($response, 0, $header_size);
    return explode(PHP_EOL, $headers);
  }

  /**
   * Get cookie headers from headers array.
   *
   * @param $headers
   *   Headers array.
   * @param $cookie_names
   *   Cookie names to get.
   *
   * @return array
   *   Array of cookies.
   */
  protected function getCookies($headers, $cookie_names) {
    $cookies = [];
    foreach ($cookie_names as $cookie_name) {
      foreach ($headers as $header) {
        $cookie = $this->getCookieValue($header, $cookie_name);
        if (!empty($cookie)) {
          $cookies[$cookie_name] = $cookie;
          break;
        }
      }
    }
    return $cookies;
  }

  /**
   * Gets cookie value of a given name from a given header.
   *
   * @param string $header
   *   Header
   * @param string $cookie_name
   *   Cookie name to search.
   *
   * @return string|null
   *   Cookie value if found, NULL otherwise.
   */
  protected function getCookieValue($header, $cookie_name) {
    // If a given cookie header.
    if (stripos($header, 'Set-Cookie') === FALSE || strpos($header, $cookie_name) === FALSE) return NULL;

    $arr = explode(';', $header);
    $val = str_ireplace('Set-Cookie:', '', $arr[0]);
    return trim($val);
  }

  /**
   * Gets X-RequestDigest xml response..
   *
   * @param $cookies
   *   Array of cookies.
   *
   * @return array
   *   'xml' - response from the server, 'http_code' - server code.
   */
  protected function getDigest($cookies) {
    $url = $this->url . 'sites/EDRFTransferPoint/_api/contextinfo';

    $curl = curl_init();
    curl_setopt_array($curl, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_HTTPHEADER => [
        'Content-Length: 0',
      ],
      CURLOPT_COOKIE => implode(';', $cookies)
    ]);
    $response = curl_exec($curl);
    $info = curl_getinfo($curl);
    curl_close($curl);

    return [
      'xml' => $response,
      'http_code' => $info['http_code'],
    ];
  }

  /**
   * Generates Sharepoint security token request body.
   *
   * @return string
   *   Request body.
   *
   * @see getSecretTokenXml()
   */
  protected function generateSecurityTokenRequestBody() {
    return <<<TOKEN
<s:Envelope xmlns:s='http://www.w3.org/2003/05/soap-envelope'
      xmlns:a='http://www.w3.org/2005/08/addressing'
      xmlns:u='http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd'>
  <s:Header>
    <a:Action s:mustUnderstand='1'>http://schemas.xmlsoap.org/ws/2005/02/trust/RST/Issue</a:Action>
    <a:ReplyTo>
      <a:Address>http://www.w3.org/2005/08/addressing/anonymous</a:Address>
    </a:ReplyTo>
    <a:To s:mustUnderstand='1'>https://login.microsoftonline.com/extSTS.srf</a:To>
    <o:Security s:mustUnderstand='1'
       xmlns:o='http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd'>
      <o:UsernameToken>
        <o:Username>$this->username</o:Username>
        <o:Password>$this->password</o:Password>
      </o:UsernameToken>
    </o:Security>
  </s:Header>
  <s:Body>
    <t:RequestSecurityToken xmlns:t='http://schemas.xmlsoap.org/ws/2005/02/trust'>
      <wsp:AppliesTo xmlns:wsp='http://schemas.xmlsoap.org/ws/2004/09/policy'>
        <a:EndpointReference>
          <a:Address>$this->url</a:Address>
        </a:EndpointReference>
      </wsp:AppliesTo>
      <t:KeyType>http://schemas.xmlsoap.org/ws/2005/05/identity/NoProofKey</t:KeyType>
      <t:RequestType>http://schemas.xmlsoap.org/ws/2005/02/trust/Issue</t:RequestType>
      <t:TokenType>urn:oasis:names:tc:SAML:1.0:assertion</t:TokenType>
    </t:RequestSecurityToken>
  </s:Body>
</s:Envelope>
TOKEN;
  }

  /**
   * Gets XML node.
   *
   * @param $xml
   *   XML to parse.
   * @param $filter
   *   Filter to select node.
   *
   * @return string
   *   XML node's content.
   */
  protected function getXmlNode($xml, $filter) {
    $crawler = new Crawler();
    // TODO: validate.
    $crawler->addXmlContent($xml);
    return $crawler
      ->filter($filter)
      ->text();
  }

}
