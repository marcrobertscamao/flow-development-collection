<?php
namespace Neos\Flow\Mvc;

use GuzzleHttp\Psr7\Utils;
use Neos\Flow\Http\Cookie;
use Neos\Flow\Mvc\Controller\AbstractController;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Psr\Http\Message\ResponseInterface;
use Neos\Flow\Annotations as Flow;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use GuzzleHttp\Psr7\Response;

/**
 * The legacy MVC response object.
 *
 * Previously Flows MVC needed a single mutable response which was passed from dispatcher to controllers
 * and even further to the view and other places via the controller context: {@see ControllerContext::getResponse()}.
 * This allowed to manipulate the response at every place.
 *
 * With the dispatcher and controllers now directly returning a response, the mutability is no longer required.
 * Additionally, the abstraction offers naturally nothing, that cant be archived by the psr response,
 * as it directly translates to one: {@see ActionResponse::buildHttpResponse()}
 *
 * So you can and should use the immutable psr {@see ResponseInterface} instead where-ever possible.
 *
 * For backwards compatibility, each controller will might now manage an own instance of the action response
 * via `$this->response` {@see AbstractController::$response} and pass it along to places.
 * But this behaviour is deprecated!
 *
 * Instead of modifying the repose via $this->response like
 *
 * - $this->response->addHttpHeader
 * - $this->response->setHttpHeader
 * - $this->response->setContentType
 * - $this->response->setStatusCode
 *
 * you can directly return a PSR repose {@see \GuzzleHttp\Psr7\Response} from a controller.
 *
 * *set status code and contents and additional header:*
 *
 * ```php
 * public function myAction()
 * {
 *     return (new Response(status: 200, body: $output))
 *         ->withAddedHeader('X-My-Header', 'foo');
 * }
 * ```
 *
 * *modify a view response with additional header:*
 *
 * ```php
 * public function myAction()
 * {
 *     $response = $this->view->render();
 *     if (!$response instanceof Response) {
 *         $response = new Response(body: $response);
 *     }
 *     return $response->withAddedHeader('X-My-Header', 'foo');
 * }
 * ```
 *
 * *render json without using the legacy json view:*
 *
 * ```php
 * public function myAction()
 * {
 *     return new Response(body: json_encode($data, JSON_THROW_ON_ERROR), headers: ['Content-Type' => 'application/json']);
 * }
 * ```
 *
 * @deprecated with Flow 9
 * @Flow\Proxy(false)
 */
final class ActionResponse
{
    /**
     * @var StreamInterface
     */
    protected $content;

    /**
     * @var UriInterface
     */
    protected $redirectUri;

    /**
     * The HTTP status code
     *
     * Note the getter has a default value,
     * but internally this can be null to signify a status code was never set explicitly.
     *
     * @var integer|null
     */
    protected $statusCode;

    /**
     * @var string
     */
    protected $contentType;

    /**
     * @var Cookie[]
     */
    protected $cookies = [];

    /**
     * @var array
     */
    protected $headers = [];

    /**
     * @var ResponseInterface|null
     */
    protected $httpResponse;

    public function __construct()
    {
        $this->content = Utils::streamFor();
    }

    /**
     * @param string|StreamInterface $content
     * @return void
     * @deprecated please use {@see ResponseInterface::withBody()} in combination with {@see \GuzzleHttp\Psr7\Utils::streamFor} instead
     */
    public function setContent($content): void
    {
        if (!$content instanceof StreamInterface) {
            $content = Utils::streamFor($content);
        }

        $this->content = $content;
    }

    /**
     * Set content mime type for this response.
     *
     * @param string $contentType
     * @return void
     * @deprecated please use {@see ResponseInterface::withHeader()} with "Content-Type" instead.
     */
    public function setContentType(string $contentType): void
    {
        $this->contentType = $contentType;
    }

    /**
     * Set a redirect URI and according status for this response.
     *
     * @param UriInterface $uri
     * @param int $statusCode
     * @return void
     * @deprecated please use {@see ResponseInterface::withStatus()} and {@see ResponseInterface::withHeader()} with "Header" instead.
     */
    public function setRedirectUri(UriInterface $uri, int $statusCode = 303): void
    {
        $this->redirectUri = $uri;
        $this->statusCode = $statusCode;
    }

    /**
     * Set the status code for this response as HTTP status code.
     * Other codes than HTTP status may end in unpredictable results.
     *
     * @param int $statusCode
     * @return void
     * @deprecated please use {@see ResponseInterface::withStatus()} instead.
     */
    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    /**
     * Set a cookie in the HTTP response
     * This leads to a corresponding `Set-Cookie` header to be set in the HTTP response
     *
     * @param Cookie $cookie Cookie to be set in the HTTP response
     * @deprecated please use {@see ResponseInterface::withHeader()} with "Set-Cookie" instead.
     */
    public function setCookie(Cookie $cookie): void
    {
        $this->cookies[$cookie->getName()] = clone $cookie;
    }

    /**
     * Delete a cooke from the HTTP response
     * This leads to a corresponding `Set-Cookie` header with an expired Cookie to be set in the HTTP response
     *
     * @param string $cookieName Name of the cookie to delete
     * @deprecated
     */
    public function deleteCookie(string $cookieName): void
    {
        $cookie = new Cookie($cookieName);
        $cookie->expire();
        $this->cookies[$cookie->getName()] = $cookie;
    }

    /**
     * Set the specified header in the response, overwriting any previous value set for this header.
     *
     * This behaviour is unsafe and partially unspecified: https://github.com/neos/flow-development-collection/issues/2492
     *
     * @param string $headerName The name of the header to set
     * @param array|string|\DateTime $headerValue An array of values or a single value for the specified header field
     * @return void
     */
    public function setHttpHeader(string $headerName, $headerValue): void
    {
        // This is taken from the Headers class, which should eventually replace this implementation and add more response API methods.
        if ($headerValue instanceof \DateTimeInterface) {
            $date = clone $headerValue;
            $date->setTimezone(new \DateTimeZone('GMT'));
            $headerValue = [$date->format(DATE_RFC2822)];
        }
        $this->headers[$headerName] = (array)$headerValue;
    }

    /**
     * Add the specified header to the response, without overwriting any previous value set for this header.
     *
     * This behaviour is unsafe and partially unspecified: https://github.com/neos/flow-development-collection/issues/2492
     *
     * @param string $headerName The name of the header to set
     * @param array|string|\DateTime $headerValue An array of values or a single value for the specified header field
     * @return void
     */
    public function addHttpHeader(string $headerName, $headerValue): void
    {
        if ($headerValue instanceof \DateTimeInterface) {
            $date = clone $headerValue;
            $date->setTimezone(new \DateTimeZone('GMT'));
            $headerValue = [$date->format(DATE_RFC2822)];
        }
        $this->headers[$headerName] = array_merge($this->headers[$headerName] ?? [], (array)$headerValue);
    }

    /**
     * Return the specified HTTP header that was previously set.
     *
     * @param string $headerName The name of the header to get the value(s) for
     * @return array|string|null An array of field values if multiple headers of that name exist, a string value if only one value exists and NULL if there is no such header.
     */
    public function getHttpHeader(string $headerName)
    {
        if (!isset($this->headers[$headerName])) {
            return null;
        }

        return count($this->headers[$headerName]) > 1 ? $this->headers[$headerName] : reset($this->headers[$headerName]);
    }

    /**
     * @return string
     */
    public function getContent(): string
    {
        $content = $this->content->getContents();
        $this->content->rewind();
        return $content;
    }

    /**
     * @return UriInterface
     */
    public function getRedirectUri(): ?UriInterface
    {
        return $this->redirectUri;
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode ?? 200;
    }

    public function hasContentType(): bool
    {
        return !empty($this->contentType);
    }

    /**
     * @return string
     */
    public function getContentType(): string
    {
        return $this->contentType ?? '';
    }

    /**
     * Unsafe. Please avoid the use of this escape hatch as the behaviour is partly unspecified
     * https://github.com/neos/flow-development-collection/issues/2492
     *
     * @param ResponseInterface $response
     */
    public function replaceHttpResponse(ResponseInterface $response): void
    {
        $this->httpResponse = $response;
    }

    /**
     * @param ActionResponse $actionResponse
     * @return ActionResponse
     */
    public function mergeIntoParentResponse(ActionResponse $actionResponse): ActionResponse
    {
        if ($this->hasContent()) {
            $actionResponse->setContent($this->content);
        }

        if ($this->hasContentType()) {
            $actionResponse->setContentType($this->contentType);
        }

        if ($this->redirectUri !== null) {
            $actionResponse->setRedirectUri($this->redirectUri);
        }

        if ($this->httpResponse !== null) {
            $actionResponse->replaceHttpResponse($this->httpResponse);
        }
        if ($this->statusCode !== null) {
            $actionResponse->setStatusCode($this->statusCode);
        }
        foreach ($this->cookies as $cookie) {
            $actionResponse->setCookie($cookie);
        }
        foreach ($this->headers as $headerName => $headerValue) {
            $actionResponse->setHttpHeader($headerName, $headerValue);
        }
        return $actionResponse;
    }

    /**
     * During the migration of {@see ActionResponse} to {@see HttpResponse} this might come in handy.
     *
     * Note this is a special use case method that will apply the internal properties
     * (Content-Type, StatusCode, Location, Set-Cookie and Content)
     * to a new or replaced PSR-7 Response and return it.
     *
     * Possibly unsafe when used in combination with {@see self::replaceHttpResponse()}
     * https://github.com/neos/flow-development-collection/issues/2492
     *
     * @return ResponseInterface
     */
    public function buildHttpResponse(): ResponseInterface
    {
        $httpResponse = $this->httpResponse ?? new Response();

        if ($this->statusCode !== null) {
            $httpResponse = $httpResponse->withStatus($this->statusCode);
        }

        if ($this->hasContent()) {
            $httpResponse = $httpResponse->withBody($this->content);
        }

        if ($this->hasContentType()) {
            $httpResponse = $httpResponse->withHeader('Content-Type', $this->contentType);
        }

        if ($this->redirectUri !== null) {
            $httpResponse = $httpResponse->withHeader('Location', (string)$this->redirectUri);
        }

        foreach ($this->headers as $headerName => $headerValue) {
            $httpResponse = $httpResponse->withAddedHeader($headerName, implode(', ', $headerValue));
        }
        foreach ($this->cookies as $cookie) {
            $httpResponse = $httpResponse->withAddedHeader('Set-Cookie', (string)$cookie);
        }

        return $httpResponse;
    }

    /**
     * Does this action response have content?
     *
     * @return bool
     */
    private function hasContent(): bool
    {
        $contentSize = $this->content->getSize();
        return $contentSize === null || $contentSize > 0;
    }
}
