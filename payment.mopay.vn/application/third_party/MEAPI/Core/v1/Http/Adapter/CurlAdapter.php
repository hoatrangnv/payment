<?php

namespace Misc\Http\Adapter;

use Misc\Http\Adapter\Curl\AbstractCurl;
use Misc\Http\Adapter\Curl\Curl;
use Misc\Http\Adapter\Curl\CurlInterface;
use Misc\Http\Client\ClientInterface;
use Misc\Http\Headers;
use Misc\Http\RequestInterface;
use Misc\Http\ResponseInterface;
use Misc\Exception\Exception;

class CurlAdapter extends AbstractAdapter {

    /**
     * @var CurlInterface
     */
    protected $curl;

    /**
     * @var \ArrayObject
     */
    protected $opts;

    /**
     * @param Client $client
     * @param CurlInterface $curl
     */
    public function __construct(ClientInterface $client, CurlInterface $curl = null) {
        parent::__construct($client);
        $this->curl = $curl ?: AbstractCurl::createOptimalVersion();
        $this->curl->init();
    }

    /**
     * @return Curl
     */
    public function getCurl() {
        return $this->curl;
    }

    /**
     * @return \ArrayObject
     */
    public function getOpts() {
        $sslVerifypeer = $this->getClient()->getSslVerifypeer();
        $caBundlePath = $this->getClient()->getCaBundlePath();
        if ($this->opts === null) {
            $this->opts = new \ArrayObject(array(
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_CAINFO => $caBundlePath,
                CURLOPT_SSL_VERIFYPEER => $sslVerifypeer,
                CURLOPT_SSL_VERIFYHOST => $sslVerifypeer
            ));
        }

        return $this->opts;
    }

    /**
     * @param \ArrayObject $opts
     */
    public function setOpts(\ArrayObject $opts) {
        $this->opts = $opts;
    }

    /**
     * @return int
     */
    protected function getheaderSize() {
        return $this->getCurl()->getInfo(CURLINFO_HEADER_SIZE);
    }

    /**
     * Extracts the headers and the body into a two-part array
     * @param string $raw_response
     * @return array
     */
    protected function extractResponseHeadersAndBody($raw_response) {
        $header_size = $this->getheaderSize();

        $raw_headers = mb_substr($raw_response, 0, $header_size);
        $raw_body = mb_substr($raw_response, $header_size);

        return array(trim($raw_headers), trim($raw_body));
    }

    /**
     * @param Headers $headers
     * @param string $raw_headers
     */
    protected function parseHeaders(Headers $headers, $raw_headers) {
        $raw_headers = str_replace("\r\n", "\n", $raw_headers);

        // There will be multiple headers if a 301 was followed
        // or a proxy was followed, etc
        $header_collection = explode("\n\n", trim($raw_headers));
        // We just want the last response (at the end)
        $raw_headers = array_pop($header_collection);

        $header_components = explode("\n", $raw_headers);
        foreach ($header_components as $line) {
            if (strpos($line, ': ') === false) {
                $headers['http_code'] = $line;
            } else {
                list ($key, $value) = explode(': ', $line);
                $headers[$key] = $value;
            }
        }
    }

    /**
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws Exception
     */
    public function sendRequest(RequestInterface $request) {
        $response = $this->getClient()->createResponse();
        $this->getCurl()->reset();
        $curlopts = array(
            CURLOPT_URL => $request->getUrl(),
        );
        //echo $request->getUrl(), "<br>";//die;
        $response->setRequest($request);

        $method = $request->getMethod();
        if ($method !== RequestInterface::METHOD_GET && $method !== RequestInterface::METHOD_POST) {
            $curlopts[CURLOPT_CUSTOMREQUEST] = $method;
        }

        $curlopts = $this->getOpts()->getArrayCopy() + $curlopts;

        if ($request->getHeaders()->count()) {
            $headers = array();
            //ar_dump($request->getHeaders());
            foreach ($request->getHeaders() as $header => $value) {
                $headers[] = "{$header}: {$value}";
            }
            //var_dump($headers);die;
            $curlopts[CURLOPT_HTTPHEADER] = $headers;
        }
        //var_dump($curlopts);die;
        $tlsvVersion = $this->getClient()->getTlsvVersion();
        //echo $tlsvVersion , "<br>";
        if ($tlsvVersion != -1) {
            $curlopts[CURLOPT_SSLVERSION] = $tlsvVersion;
        }
        $authorized = $request->getAuthorized();

        if ($authorized != null) {
            $curlopts[CURLOPT_USERPWD] = $authorized->getString();
        }
        $postfields = array();
        if ($method === RequestInterface::METHOD_POST && $request->getFileParams()->count()) {
            $postfields = array_merge(
                    $postfields, array_map(
                            array($this->getCurl(), 'preparePostFileField'), $request->getFileParams()->getArrayCopy()));
        }

        if ($method !== RequestInterface::METHOD_GET && $request->getBodyParams()->count()) {
            $postfields = array_merge($postfields, $request->getBodyParams()->export());
        }

        $postType = $request->getPostArray();
        if (!empty($postfields) && $postType == true) {
            $curlopts[CURLOPT_POSTFIELDS] = $postfields;
        } if (!empty($postfields) && $postType == 2) {
            $curlopts[CURLOPT_POST] = 1;
            $curlopts[CURLOPT_POSTFIELDS] = http_build_query($postfields);
        } elseif (!empty($postfields) && $postType == false) {
            $curlopts[CURLOPT_POSTFIELDS] = json_encode($postfields);
        }
        $this->getCurl()->setoptArray($curlopts);
        $raw_response = $this->getCurl()->exec();

        $status_code = $this->getCurl()->getInfo(CURLINFO_HTTP_CODE);
        $curl_errno = $this->getCurl()->errno();
        $curl_error = $curl_errno ? $this->getCurl()->error() : null;

        $response_parts = $this->extractResponseHeadersAndBody($raw_response);

        //var_dump($response_parts);
        $response->setStatusCode($status_code);
        $this->parseHeaders($response->getHeaders(), $response_parts[0]);
        $response->setBody($response_parts[1]);

        if ($curl_errno) {
            $this->getClient()->getApi()->getCurlLogger()->log("error", "curl", $raw_response, array($curl_error, $curl_errno));
            throw new Exception($curl_error, $curl_errno);
        }
        //var_dump($response);
        return $response;
    }

}
