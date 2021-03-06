<?php


namespace Lineten\CurlClient\Response;

use Lineten\CurlClient\CurlResponse;
use Lineten\CurlClient\Constant\ContentType;
use Lineten\CurlClient\Constant\HttpRequestHeader;
use Lineten\CurlClient\Exception\CurlClientException;

class JsonResponse
{
    /** @var CurlResponse $res */
    protected $res;

    /**
     * JsonResponse constructor.
     * @param CurlResponse $res
     */
    public function __construct(CurlResponse $res)
    {
        $this->res = $res;
    }

    /**
     * @param bool $assoc
     * @return mixed
     */
    public function getJson($assoc = true)
    {
        return json_decode($this->res->getBody()->__toString(), $assoc);
    }

    /**
     * Check if the provided response Content-Type is JSON
     * @throws CurlClientException Provided Content-Type is not application/json
     */
    public function checkContentType()
    {
        $contentType = $this->res->getHeaderLine(HttpRequestHeader::CONTENT_TYPE);
        if (strpos($contentType, ContentType::APPLICATION_JSON) !== 0) {
            throw new CurlClientException('Invalid JSON response Content-Type "' . $contentType . '"');
        }
    }
}
