<?php
namespace Simple\Http\Request\Adapter;

use Simple\Http\Response\ResponseInterface;
use Simple\Http\Response\Response;
use Simple\Http\Request\RequestInterface;

/**
 * Curl Adapter
 * @author Isaque de Souza <isaquesb@gmail.com>
 */
class CurlAdapter implements AdapterInterface
{

    /**
     * @var array
     */
    protected $options = [
        CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ];

    /**
     * Response
     * @var ResponseInterface
     */
    protected $response;

    /**
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->setOptions($options);
    }

    /**
     * @param array $options
     * @return CurlAdapter
     */
    public function setOptions(array $options)
    {
        if (array_key_exists(CURLOPT_RETURNTRANSFER, $options)) {
            unset($options[CURLOPT_RETURNTRANSFER]);
        }
        $this->options = array_replace($this->options, $options);
        return $this;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param RequestInterface $request
     * @return bool
     */
    public function dispatch(RequestInterface $request)
    {
        $response = $this->processRequest($request);
        if ($response) {
            $this->response = $response;
        }
        return $response;
    }

    /**
     * @param RequestInterface $request
     * @return array
     */
    protected function getRequestDispatchHeaders(RequestInterface $request)
    {
        $headers = $request->getHeaders();
        $headers['Expect'] = '';
        $data = $request->getData();
        $params = $request->getParams();
        $isPostOrPut = in_array($request->getMethod(), ['POST', 'PUT']);
        if ($isPostOrPut && $params instanceof \JsonSerializable) {
            $headers['Content-Type'] = 'application/json';
        }
        if (!array_key_exists('Content-Type', $headers) || is_array($data)) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $headers['Content-Type'] .= '; charset=' . $request->getCharset();
        return $this->prepareHeaders($headers);
    }

    /**
     * @param RequestInterface $request
     * @return array
     */
    protected function getRequestDispatchOptions(RequestInterface $request)
    {
        $url = $request->getUrl();
        $options = array_replace($this->getOptions(), [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->getRequestDispatchHeaders($request),
            CURLOPT_TIMEOUT => $request->getTimeout(),
        ]);
        $options[CURLOPT_CUSTOMREQUEST] = $request->getMethod();
        $params = $request->getParams();
        switch ($options[CURLOPT_CUSTOMREQUEST]) {
            case 'POST':
                $options[CURLOPT_POSTFIELDS] = $request->getData();
            case 'GET':
                $glue = strpos($url, '?') !== false ? '&' : '?';
                $url .= $params ? $glue . http_build_query($params) : null;
                break;
        }
        $options[CURLOPT_URL] = $url;
        return $options;
    }

    /**
     * @param array $originalHeaders
     * @return array
     */
    protected function prepareHeaders(array $originalHeaders)
    {
        $allHeaders = [];
        foreach ($originalHeaders as $key => $value) {
            $stringKey = implode('-', array_map('ucfirst', explode('-', $key)));
            $allHeaders[] = $stringKey . ':' . implode(',', (array) $value);
        }
        return $allHeaders;
    }

    /**
     * @param int $status
     * @param string $body
     * @param int $errorNo
     * @param string $error
     * @return ResponseInterface
     */
    private function makeResponse($status, $body, $errorNo, $error)
    {
        $errors = [];
        if (!empty($error)) {
            $errors[$errorNo] = $error;
        }
        $response = new Response($status, $body, $errors);
        return $response;
    }

    /**
     * @param RequestInterface $request
     * @return ResponseInterface|bool FALSE on failure
     */
    private function processRequest(RequestInterface $request)
    {
        $resource = curl_init();
        $options = $this->getRequestDispatchOptions($request);
        curl_setopt_array($resource, $options);
        $body = curl_exec($resource);
        if ($body === false) {
            return false;
        }
        $status =  (int) curl_getinfo($resource, CURLINFO_HTTP_CODE);
        $error = curl_error($resource);
        $errorNo = curl_errno($resource);
        curl_close($resource);
        return $this->makeResponse($status, $body, $errorNo, $error);
    }
}
