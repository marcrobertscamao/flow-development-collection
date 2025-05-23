<?php
namespace Neos\Flow\Mvc\Controller;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\FlashMessage\FlashMessageContainer;
use Neos\Flow\Mvc\FlashMessage\FlashMessageService;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Routing\UriBuilder;

/**
 * The controller context holds information about the request, response, arguments
 * and further details of a controller. Instances of this class act as a container
 * for conveniently passing the information to other classes who need it, usually
 * views being views or view helpers.
 *
 * @api
 */
class ControllerContext
{
    /**
     * @var ActionRequest
     */
    protected $request;

    /**
     * @var ActionResponse
     */
    protected $response;

    /**
     * @var Arguments
     */
    protected $arguments;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * @Flow\Inject
     * @var FlashMessageService
     */
    protected $flashMessageService;

    /**
     * Constructs this context
     *
     * @param ActionRequest $request
     * @param ActionResponse $response
     * @param Arguments $arguments
     * @param UriBuilder $uriBuilder
     */
    public function __construct(ActionRequest $request, ActionResponse $response, Arguments $arguments, UriBuilder $uriBuilder)
    {
        $this->request = $request;
        $this->response = $response;
        $this->arguments = $arguments;
        $this->uriBuilder = $uriBuilder;
    }

    /**
     * Get the request of the controller
     *
     * @return ActionRequest
     * @api
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * The legacy response of the controller.
     *
     * @return ActionResponse
     * @deprecated with Flow 9 {@see ActionResponse}
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Get the arguments of the controller
     *
     * @return Arguments
     * @api
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * Returns the URI Builder bound to this context
     *
     * @return UriBuilder
     * @api
     */
    public function getUriBuilder()
    {
        return $this->uriBuilder;
    }

    /**
     * Get the flash message container
     *
     * @return FlashMessageContainer A container for flash messages
     * @api
     */
    public function getFlashMessageContainer()
    {
        return $this->flashMessageService->getFlashMessageContainerForRequest($this->request);
    }
}
